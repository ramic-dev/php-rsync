<?php

declare(strict_types=1);

namespace Ramic\Rsync\FileSystem;

class FileInfo
{
    public const TYPE_FILE    = 'file';
    public const TYPE_DIR     = 'dir';
    public const TYPE_SYMLINK = 'link';

    public function __construct(
        public readonly string $relativePath,
        public readonly string $absolutePath,
        public readonly string $type,
        public readonly int    $size,
        public readonly int    $mtime,
        public readonly int    $permissions,
        public readonly ?string $linkTarget = null,
    ) {}

    public function isFile(): bool    { return $this->type === self::TYPE_FILE; }
    public function isDirectory(): bool { return $this->type === self::TYPE_DIR; }
    public function isSymlink(): bool   { return $this->type === self::TYPE_SYMLINK; }
}
