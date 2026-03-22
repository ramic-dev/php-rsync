<?php

declare(strict_types=1);

namespace Ramic\Rsync\Algorithm;

/**
 * Reconstructs the new file content by applying a delta to the destination file.
 */
class DeltaApplicator
{
    /**
     * @param DeltaInstruction[] $instructions
     */
    public function apply(string $destination, array $instructions, int $blockSize): string
    {
        $destLen = strlen($destination);
        $result  = '';

        foreach ($instructions as $instruction) {
            if ($instruction instanceof CopyInstruction) {
                // NB4 fix: guard against out-of-bounds block references.
                $offset = $instruction->blockIndex * $blockSize;
                if ($offset > $destLen) {
                    throw new \RuntimeException(
                        "CopyInstruction references block {$instruction->blockIndex} "
                        . "which is outside the destination (length {$destLen}).",
                    );
                }
                $result .= substr($destination, $offset, $instruction->blockSize);
            } elseif ($instruction instanceof LiteralInstruction) {
                $result .= $instruction->data;
            }
        }

        return $result;
    }
}
