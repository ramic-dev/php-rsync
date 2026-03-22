<?php

declare(strict_types=1);

namespace Ramic\Rsync\Filter;

class FilterRule
{
    public const INCLUDE = 'include';
    public const EXCLUDE = 'exclude';

    private bool $anchored;       // pattern starts with /
    private bool $dirOnly;        // pattern ends with /
    private string $cleanPattern;

    public function __construct(
        public readonly string $type,
        public readonly string $pattern,
    ) {
        $this->anchored     = str_starts_with($pattern, '/');
        $this->dirOnly      = str_ends_with($pattern, '/');
        $this->cleanPattern = trim($pattern, '/');
    }

    public function matches(string $relativePath, bool $isDir): bool
    {
        if ($this->dirOnly && !$isDir) {
            return false;
        }

        $path = str_replace('\\', '/', $relativePath);

        if ($this->anchored) {
            return fnmatch($this->cleanPattern, $path);
        }

        // Match against the basename or any path component
        $basename = basename($path);
        if (fnmatch($this->cleanPattern, $basename)) {
            return true;
        }

        // Also try matching against the full relative path
        return fnmatch($this->cleanPattern, $path);
    }

    public function isExclude(): bool { return $this->type === self::EXCLUDE; }
    public function isInclude(): bool { return $this->type === self::INCLUDE; }
}
