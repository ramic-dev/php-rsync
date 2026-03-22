<?php

declare(strict_types=1);

namespace Ramic\Rsync\Algorithm;

/**
 * Generates a delta (list of instructions) that transforms the destination
 * file into the source file, using the rsync rolling-checksum algorithm.
 *
 * Algorithm:
 *  1. Slide a window of blockSize bytes over the source.
 *  2. At each position, compute the rolling (weak) checksum.
 *  3. If the weak checksum matches any destination block, verify with MD5.
 *  4. On match: emit CopyInstruction, advance by blockSize.
 *  5. On miss:  accumulate one literal byte, advance by 1.
 */
class DeltaGenerator
{
    /**
     * @return DeltaInstruction[]
     */
    public function generate(string $source, BlockChecksums $destChecksums, int $blockSize): array
    {
        $sourceLen = strlen($source);

        if ($sourceLen === 0) {
            return [];
        }

        if ($sourceLen < $blockSize || $destChecksums->getBlockCount() === 0) {
            return [new LiteralInstruction($source)];
        }

        $instructions = [];
        $literalBuffer = '';
        $pos = 0;

        $roller = new RollingChecksum();
        $roller->init(substr($source, 0, $blockSize));

        while ($pos <= $sourceLen - $blockSize) {
            $weak = $roller->getValue();

            if ($destChecksums->hasWeak($weak)) {
                $block = substr($source, $pos, $blockSize);
                $strong = md5($block);
                $blockIndex = $destChecksums->findBlock($weak, $strong);

                if ($blockIndex !== null) {
                    if ($literalBuffer !== '') {
                        $instructions[] = new LiteralInstruction($literalBuffer);
                        $literalBuffer = '';
                    }
                    $instructions[] = new CopyInstruction($blockIndex, $blockSize);
                    $pos += $blockSize;

                    if ($pos <= $sourceLen - $blockSize) {
                        $roller->init(substr($source, $pos, $blockSize));
                    }
                    continue;
                }
            }

            // No match: slide by one byte
            $literalBuffer .= $source[$pos];
            $pos++;

            if ($pos <= $sourceLen - $blockSize) {
                $roller->roll(ord($source[$pos - 1]), ord($source[$pos + $blockSize - 1]));
            }
        }

        // Tail bytes that do not fill a complete window
        if ($pos < $sourceLen) {
            $literalBuffer .= substr($source, $pos);
        }

        if ($literalBuffer !== '') {
            $instructions[] = new LiteralInstruction($literalBuffer);
        }

        return $instructions;
    }
}
