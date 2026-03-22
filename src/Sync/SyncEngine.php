<?php

declare(strict_types=1);

namespace Ramic\Rsync\Sync;

use Ramic\Rsync\Algorithm\BlockChecksums;
use Ramic\Rsync\Algorithm\BlockSize;
use Ramic\Rsync\Algorithm\StreamDeltaProcessor;
use Ramic\Rsync\FileSystem\FileInfo;
use Ramic\Rsync\FileSystem\Scanner;

class SyncEngine
{
    /** @var callable(string): void|null */
    private $logger = null;

    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
    }

    public function sync(string $source, string $destination, SyncOptions $options): SyncStats
    {
        $stats = new SyncStats();

        $source      = rtrim($source, DIRECTORY_SEPARATOR . '/');
        $destination = rtrim($destination, DIRECTORY_SEPARATOR . '/');

        if (!file_exists($source)) {
            throw new \RuntimeException("Source does not exist: $source");
        }

        // Single-file sync
        if (is_file($source)) {
            $this->ensureDir(dirname($destination), $options, $stats);
            $destInfo = is_file($destination) ? $this->makeFileInfo($destination, '') : null;
            $srcInfo  = $this->makeFileInfo($source, '');
            if ($this->needsUpdate($srcInfo, $destInfo, $options)) {
                $this->transferFile($source, $destination, $options, $stats);
            }
            $stats->finish();
            return $stats;
        }

        if (!is_dir($source)) {
            throw new \RuntimeException("Source is not a file or directory: $source");
        }

        $scanner = new Scanner();
        $filter  = $options->filter->isEmpty() ? null : $options->filter;

        // B4 fix: pass $options->recursive so the scanner respects the flag.
        $sourceItems = $scanner->scan($source, $filter, $options->recursive);
        // Apply the same filter to the destination scan so excluded paths are
        // not treated as "missing from source" and incorrectly deleted.
        $destItems   = is_dir($destination) ? $scanner->scan($destination, $filter, $options->recursive) : [];

        $destMap = [];
        foreach ($destItems as $item) {
            $destMap[$item->relativePath] = $item;
        }

        $this->ensureDir($destination, $options, $stats);

        $sourcePaths = [];

        foreach ($sourceItems as $item) {
            $sourcePaths[$item->relativePath] = true;
            $destAbsPath = $destination . DIRECTORY_SEPARATOR . $item->relativePath;

            if ($item->isDirectory()) {
                $this->ensureDir($destAbsPath, $options, $stats);
                continue;
            }

            if ($item->isSymlink() && $options->preserveLinks) {
                $this->syncSymlink($item, $destAbsPath, $options, $stats);
                continue;
            }

            if ($item->isFile()) {
                $destItem = $destMap[$item->relativePath] ?? null;
                if ($this->needsUpdate($item, $destItem, $options)) {
                    $this->transferFile($item->absolutePath, $destAbsPath, $options, $stats);
                    $this->log($options, "transferred: {$item->relativePath}");
                } else {
                    $stats->recordSkip();
                }
            }
        }

        // Handle --delete
        if ($options->delete) {
            // Process in reverse so children are removed before parents.
            foreach (array_reverse($destItems) as $item) {
                if (isset($sourcePaths[$item->relativePath])) {
                    continue;
                }

                $absPath = $destination . DIRECTORY_SEPARATOR . $item->relativePath;

                if (!$options->dryRun) {
                    // NB3 fix: no @ suppressor, check return values individually.
                    if ($item->isDirectory()) {
                        if (!rmdir($absPath)) {
                            $this->log($options, "warning: cannot remove dir (may not be empty): {$item->relativePath}");
                            continue;
                        }
                    } else {
                        if (!unlink($absPath)) {
                            $this->log($options, "warning: cannot remove file: {$item->relativePath}");
                            continue;
                        }
                    }
                }

                $stats->recordDelete($absPath);
                $this->log($options, "deleted: {$item->relativePath}");
            }
        }

        $stats->finish();
        return $stats;
    }

    // -------------------------------------------------------------------------

    private function needsUpdate(FileInfo $src, ?FileInfo $dest, SyncOptions $options): bool
    {
        if ($dest === null) {
            return true;
        }

        if ($options->checksum) {
            return md5_file($src->absolutePath) !== md5_file($dest->absolutePath);
        }

        return $src->size !== $dest->size || $src->mtime !== $dest->mtime;
    }

    private function transferFile(
        string      $sourcePath,
        string      $destPath,
        SyncOptions $options,
        SyncStats   $stats,
    ): void {
        $totalBytes   = (int) filesize($sourcePath);
        $literalBytes = $totalBytes;

        if (!$options->dryRun) {
            // B3 fix: resolve type conflict.
            if (is_dir($destPath)) {
                if (!rmdir($destPath)) {
                    throw new \RuntimeException(
                        "Destination '$destPath' is a non-empty directory; cannot replace with file.",
                    );
                }
            }

            $dir     = dirname($destPath);
            $tmpPath = tempnam($dir, '.rsync_tmp_');
            if ($tmpPath === false) {
                throw new \RuntimeException("Cannot create temp file in: $dir");
            }

            try {
                if (is_file($destPath) && filesize($destPath) > 0) {
                    // Stream-based delta: neither file is fully loaded into memory.
                    $destSize  = (int) filesize($destPath);
                    $blockSize = $options->blockSize ?? BlockSize::compute($destSize);
                    $checksums = BlockChecksums::fromFile($destPath, $blockSize);
                    $processor = new StreamDeltaProcessor();
                    $literalBytes = $processor->process($sourcePath, $destPath, $checksums, $blockSize, $tmpPath);
                } else {
                    // New file: stream copy in 8 MB chunks.
                    $srcFp = fopen($sourcePath, 'rb');
                    $tmpFp = fopen($tmpPath, 'wb');
                    while (!feof($srcFp)) {
                        fwrite($tmpFp, fread($srcFp, 8 * 1024 * 1024));
                    }
                    fclose($srcFp);
                    fclose($tmpFp);
                }

                if (!rename($tmpPath, $destPath)) {
                    throw new \RuntimeException("Cannot rename temp file to: $destPath");
                }
            } catch (\Throwable $e) {
                @unlink($tmpPath);
                throw $e;
            }

            if ($options->preserveTimes) {
                touch($destPath, filemtime($sourcePath));
            }
            if ($options->preservePermissions) {
                chmod($destPath, fileperms($sourcePath) & 0777);
            }
        }

        $stats->recordTransfer($destPath, $totalBytes, $literalBytes);
    }

    private function syncSymlink(FileInfo $src, string $destPath, SyncOptions $options, SyncStats $stats): void
    {
        if ($src->linkTarget === null) {
            return;
        }

        if (!$options->dryRun) {
            if (is_link($destPath)) {
                unlink($destPath);
            }
            symlink($src->linkTarget, $destPath);
        }

        $stats->recordTransfer($destPath, 0, 0);
    }

    private function ensureDir(string $path, SyncOptions $options, SyncStats $stats): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!$options->dryRun) {
            // B3 fix: if a file exists where we need a directory, remove it first.
            if (file_exists($path)) {
                if (!unlink($path)) {
                    throw new \RuntimeException(
                        "Cannot remove file '$path' conflicting with required directory.",
                    );
                }
            }

            if (!mkdir($path, 0755, true)) {
                throw new \RuntimeException("Cannot create directory: $path");
            }
        }

        $stats->recordDirCreated();
    }

    private function makeFileInfo(string $absPath, string $relPath): FileInfo
    {
        return new FileInfo(
            relativePath: $relPath,
            absolutePath: $absPath,
            type:         is_dir($absPath) ? FileInfo::TYPE_DIR : FileInfo::TYPE_FILE,
            size:         is_file($absPath) ? (int) filesize($absPath) : 0,
            mtime:        (int) filemtime($absPath),
            permissions:  (int) fileperms($absPath),
        );
    }

    private function log(SyncOptions $options, string $message): void
    {
        if ($options->verbose && $this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
