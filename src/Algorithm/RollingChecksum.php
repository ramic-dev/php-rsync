<?php

declare(strict_types=1);

namespace Ramic\Rsync\Algorithm;

/**
 * Rolling checksum based on the rsync paper by Andrew Tridgell.
 *
 * For a block [X_k .. X_{k+L-1}]:
 *   a = (Σ X_i) mod M
 *   b = (Σ (L-i) * X_i) mod M   [i from 0 to L-1]
 *   checksum = a | (b << 16)
 *
 * When sliding the window by one byte (remove outByte, add inByte):
 *   a_new = (a - out + in) mod M
 *   b_new = (b - L*out + a_new) mod M
 */
class RollingChecksum
{
    private const MOD = 65536; // 2^16

    private int $a = 0;
    private int $b = 0;
    private int $blockSize = 0;

    public function init(string $block): void
    {
        $this->blockSize = strlen($block);
        $this->a = 0;
        $this->b = 0;

        for ($i = 0; $i < $this->blockSize; $i++) {
            $byte = ord($block[$i]);
            $this->a += $byte;
            $this->b += ($this->blockSize - $i) * $byte;
        }

        $this->a %= self::MOD;
        $this->b %= self::MOD;
    }

    /**
     * Slide the window: remove outByte from the left, add inByte on the right.
     */
    public function roll(int $outByte, int $inByte): void
    {
        $this->a = (($this->a - $outByte + $inByte) % self::MOD + self::MOD) % self::MOD;
        $this->b = (($this->b - $this->blockSize * $outByte + $this->a) % self::MOD + self::MOD) % self::MOD;
    }

    public function getValue(): int
    {
        return $this->a | ($this->b << 16);
    }
}
