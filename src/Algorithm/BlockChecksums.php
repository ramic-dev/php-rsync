<?php

declare(strict_types=1);

namespace Ramic\Rsync\Algorithm;

/**
 * Computes and stores weak+strong checksums for each fixed-size block of a file.
 * Only complete blocks are indexed; the final partial block is excluded.
 *
 * Index structure: weakChecksum => [[blockIndex, strongChecksum], ...]
 */
class BlockChecksums
{
    /** @var array<int, list<array{int, string}>> */
    private array $weakToBlocks = [];
    private int $blockCount = 0;

    private function __construct() {}

    /**
     * Build checksums by streaming a file — no full file load into memory.
     */
    public static function fromFile(string $path, int $blockSize): self
    {
        $instance = new self();
        $fp       = fopen($path, 'rb');
        $roller   = new RollingChecksum();
        $index    = 0;

        while (!feof($fp)) {
            $block = fread($fp, $blockSize);
            if (strlen($block) < $blockSize) {
                break; // skip partial last block
            }
            $roller->init($block);
            $weak   = $roller->getValue();
            $strong = md5($block);
            $instance->weakToBlocks[$weak][] = [$index, $strong];
            $instance->blockCount++;
            $index++;
        }

        fclose($fp);
        return $instance;
    }

    public static function fromContent(string $content, int $blockSize): self
    {
        $instance = new self();
        $len = strlen($content);
        $roller = new RollingChecksum();

        for ($offset = 0, $index = 0; $offset + $blockSize <= $len; $offset += $blockSize, $index++) {
            $block = substr($content, $offset, $blockSize);
            $roller->init($block);
            $weak = $roller->getValue();
            $strong = md5($block);

            $instance->weakToBlocks[$weak][] = [$index, $strong];
            $instance->blockCount++;
        }

        return $instance;
    }

    public function hasWeak(int $weak): bool
    {
        return isset($this->weakToBlocks[$weak]);
    }

    public function findBlock(int $weak, string $strong): ?int
    {
        if (!isset($this->weakToBlocks[$weak])) {
            return null;
        }
        foreach ($this->weakToBlocks[$weak] as [$index, $blockStrong]) {
            if ($blockStrong === $strong) {
                return $index;
            }
        }
        return null;
    }

    public function getBlockCount(): int
    {
        return $this->blockCount;
    }

    /**
     * Reconstruct a BlockChecksums from a serialized array (e.g. received over HTTP).
     * Expected format: [['weak' => int, 'strong' => string], ...] indexed by block order.
     */
    public static function fromArray(array $checksums): self
    {
        $instance = new self();
        foreach ($checksums as $index => $item) {
            $weak   = (int) $item['weak'];
            $strong = (string) $item['strong'];
            $instance->weakToBlocks[$weak][] = [$index, $strong];
            $instance->blockCount++;
        }
        return $instance;
    }

    /**
     * Serialize to array for JSON transport.
     * Returns [['weak' => int, 'strong' => string], ...] sorted by block index.
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->weakToBlocks as $weak => $blocks) {
            foreach ($blocks as [$index, $strong]) {
                $result[$index] = ['weak' => $weak, 'strong' => $strong];
            }
        }
        ksort($result);
        return array_values($result);
    }
}
