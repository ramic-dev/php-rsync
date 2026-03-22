<?php

declare(strict_types=1);

namespace Ramic\Rsync\FileSystem;

use Ramic\Rsync\Filter\FilterList;

class Scanner
{
    /**
     * Scan a directory and return FileInfo objects sorted so that parent
     * directories always precede their children.
     *
     * B1 fix: use RecursiveCallbackFilterIterator so excluded directories are
     *         never descended into (instead of calling next() on the iterator).
     * B2 fix: use substr() with a precomputed prefix length instead of
     *         str_replace() to extract the relative path.
     *
     * @return FileInfo[]
     */
    public function scan(string $root, ?FilterList $filter = null, bool $recursive = true): array
    {
        // Normalise to forward slashes so substr offset is consistent on Windows.
        $rootNorm  = rtrim(str_replace('\\', '/', $root), '/');
        $prefixLen = strlen($rootNorm) + 1; // +1 for the trailing separator

        $dirIterator = new \RecursiveDirectoryIterator(
            $rootNorm,
            \FilesystemIterator::SKIP_DOTS,
        );

        // Wrap with filter before handing to RecursiveIteratorIterator so that
        // excluded directories are pruned and their children are never visited.
        if ($filter !== null) {
            $dirIterator = new \RecursiveCallbackFilterIterator(
                $dirIterator,
                static function (\SplFileInfo $item) use ($prefixLen, $filter): bool {
                    $absNorm = str_replace('\\', '/', $item->getPathname());
                    $relPath = substr($absNorm, $prefixLen);
                    return !$filter->isExcluded($relPath, $item->isDir());
                },
            );
        }

        // B4 fix: respect $recursive flag — use a flat iteration for top-level only.
        $iterator = $recursive
            ? new \RecursiveIteratorIterator($dirIterator, \RecursiveIteratorIterator::SELF_FIRST)
            : $dirIterator;

        $results = [];

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $absNorm = str_replace('\\', '/', $item->getPathname());
            $relPath = substr($absNorm, $prefixLen);
            $absPath = $item->getPathname();

            if ($item->isLink()) {
                $results[] = new FileInfo(
                    relativePath: $relPath,
                    absolutePath: $absPath,
                    type:         FileInfo::TYPE_SYMLINK,
                    size:         0,
                    mtime:        $item->getMTime(),
                    permissions:  $item->getPerms(),
                    linkTarget:   readlink($absPath),
                );
            } elseif ($item->isDir()) {
                $results[] = new FileInfo(
                    relativePath: $relPath,
                    absolutePath: $absPath,
                    type:         FileInfo::TYPE_DIR,
                    size:         0,
                    mtime:        $item->getMTime(),
                    permissions:  $item->getPerms(),
                );
            } else {
                $results[] = new FileInfo(
                    relativePath: $relPath,
                    absolutePath: $absPath,
                    type:         FileInfo::TYPE_FILE,
                    size:         $item->getSize(),
                    mtime:        $item->getMTime(),
                    permissions:  $item->getPerms(),
                );
            }
        }

        // Directories before files, then alphabetically within each group.
        usort($results, static function (FileInfo $a, FileInfo $b): int {
            if ($a->isDirectory() !== $b->isDirectory()) {
                return $a->isDirectory() ? -1 : 1;
            }
            return strcmp($a->relativePath, $b->relativePath);
        });

        return $results;
    }
}
