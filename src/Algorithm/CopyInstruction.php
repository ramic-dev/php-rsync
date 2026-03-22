<?php

declare(strict_types=1);

namespace Ramic\Rsync\Algorithm;

/**
 * Copy a block from the destination file (already present on the receiver).
 */
class CopyInstruction implements DeltaInstruction
{
    public function __construct(
        public readonly int $blockIndex,
        public readonly int $blockSize,
    ) {}
}
