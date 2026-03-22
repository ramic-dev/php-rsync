<?php

declare(strict_types=1);

namespace Ramic\Rsync\Algorithm;

class BlockSize
{
    private const MIN = 700;
    private const MAX = 131072; // 128 KB

    /**
     * Compute the optimal block size for a file of the given length.
     * Mirrors rsync's heuristic: max(700, ceil(sqrt(fileSize))).
     */
    public static function compute(int $fileSize): int
    {
        if ($fileSize <= 0) {
            return self::MIN;
        }

        $size = (int) ceil(sqrt($fileSize));
        return max(self::MIN, min($size, self::MAX));
    }
}
