<?php

declare(strict_types=1);

namespace Ramic\Rsync\Transport;

use Ramic\Rsync\Algorithm\BlockSize;
use Ramic\Rsync\Algorithm\DeltaGenerator;
use Ramic\Rsync\Algorithm\LiteralInstruction;
use Ramic\Rsync\FileSystem\FileInfo;
use Ramic\Rsync\FileSystem\Scanner;
use Ramic\Rsync\Sync\SyncOptions;
use Ramic\Rsync\Sync\SyncStats;

/**
 * Orchestrates a local → remote sync using the HTTP transport.
 *
 * Protocol per file:
 *   new file   → sendFile (full content, base64)
 *   changed    → getBlockChecksums → DeltaGenerator (local) → applyDelta
 *   unchanged  → skip
 *   --delete   → deleteFile for each remote path absent from local
 */
class RemoteSyncEngine
{
    /** @var callable(string): void|null */
    private $logger = null;

    public function __construct(private readonly HttpTransport $transport) {}

    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
    }

    public function sync(string $source, SyncOptions $options): SyncStats
    {
        $stats  = new SyncStats();
        $source = rtrim($source, DIRECTORY_SEPARATOR . '/');

        if (!file_exists($source)) {
            throw new \RuntimeException("Source does not exist: $source");
        }

        $scanner    = new Scanner();
        $filter     = $options->filter->isEmpty() ? null : $options->filter;
        $localItems = $scanner->scan($source, $filter, $options->recursive);

        // Fetch remote file list and apply the same filter client-side,
        // so excluded paths are not treated as "missing" during --delete.
        $allRemote = $this->transport->listFiles();
        $remoteFiles = $this->applyFilterToRemote($allRemote, $filter);

        $localPaths = [];

        foreach ($localItems as $item) {
            $localPaths[$item->relativePath] = true;

            if ($item->isDirectory()) {
                if (!isset($remoteFiles[$item->relativePath])) {
                    if (!$options->dryRun) {
                        $this->transport->makeDir($item->relativePath);
                    }
                    $stats->recordDirCreated();
                    $this->log($options, "mkdir: {$item->relativePath}");
                }
                continue;
            }

            if (!$item->isFile()) {
                continue;
            }

            $remote      = $remoteFiles[$item->relativePath] ?? null;
            $needsUpdate = $this->needsUpdate($item, $remote, $options);

            if (!$needsUpdate) {
                $stats->recordSkip();
                continue;
            }

            $sourceContent = file_get_contents($item->absolutePath);
            if ($sourceContent === false) {
                throw new \RuntimeException("Cannot read: {$item->absolutePath}");
            }

            $totalBytes   = strlen($sourceContent);
            $literalBytes = $totalBytes;
            $mtime        = $options->preserveTimes ? $item->mtime : null;

            if (!$options->dryRun) {
                if ($remote !== null && $remote['type'] === 'file' && $remote['size'] > 0) {
                    // Delta path
                    $blockSize    = $options->blockSize ?? BlockSize::compute($remote['size']);
                    $checksums    = $this->transport->getBlockChecksums($item->relativePath, $blockSize);
                    $generator    = new DeltaGenerator();
                    $instructions = $generator->generate($sourceContent, $checksums, $blockSize);

                    $literalBytes = 0;
                    foreach ($instructions as $instr) {
                        if ($instr instanceof LiteralInstruction) {
                            $literalBytes += strlen($instr->data);
                        }
                    }

                    $this->transport->applyDelta($item->relativePath, $instructions, $blockSize, $mtime);
                } else {
                    // Full upload
                    $this->transport->sendFile($item->relativePath, $sourceContent, $mtime);
                }
            }

            $stats->recordTransfer($item->relativePath, $totalBytes, $literalBytes);
            $this->log($options, "transferred: {$item->relativePath}");
        }

        if ($options->delete) {
            // Delete in reverse order so children precede parents
            $remotePaths = array_keys($remoteFiles);
            rsort($remotePaths);

            foreach ($remotePaths as $relPath) {
                if (isset($localPaths[$relPath])) {
                    continue;
                }
                if (!$options->dryRun) {
                    $this->transport->deleteFile($relPath);
                }
                $stats->recordDelete($relPath);
                $this->log($options, "deleted: $relPath");
            }
        }

        $stats->finish();
        return $stats;
    }

    // -------------------------------------------------------------------------

    private function needsUpdate(FileInfo $src, ?array $remote, SyncOptions $options): bool
    {
        if ($remote === null) {
            return true;
        }
        if ($options->checksum) {
            // For remote checksum comparison we fall back to mtime+size;
            // a dedicated remote-checksum action can be added later.
            return $src->size !== (int) $remote['size'];
        }
        return $src->size !== (int) $remote['size'] || $src->mtime !== (int) $remote['mtime'];
    }

    /**
     * Apply include/exclude rules to the remote file map (client-side).
     *
     * @param  array<string, array{size: int, mtime: int, type: string}> $remoteFiles
     * @return array<string, array{size: int, mtime: int, type: string}>
     */
    private function applyFilterToRemote(array $remoteFiles, ?\Ramic\Rsync\Filter\FilterList $filter): array
    {
        if ($filter === null) {
            return $remoteFiles;
        }
        $result = [];
        foreach ($remoteFiles as $path => $info) {
            if (!$filter->isExcluded($path, $info['type'] === 'dir')) {
                $result[$path] = $info;
            }
        }
        return $result;
    }

    private function log(SyncOptions $options, string $message): void
    {
        if ($options->verbose && $this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
