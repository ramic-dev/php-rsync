<?php

declare(strict_types=1);

namespace Ramic\Rsync\Algorithm;

/**
 * Stream-based delta processor: generates a delta AND applies it in one pass,
 * writing directly to an output file. Neither source nor destination is ever
 * fully loaded into memory.
 *
 * Memory usage: O(CHUNK_SIZE + blockChecksumIndex)
 *   Typical peak for a 1 GB file: ≈ 18 MB regardless of file size.
 *
 * Algorithm:
 *  - Source is read in CHUNK_SIZE windows via a sliding buffer.
 *  - Rolling checksum slides byte-by-byte over the source.
 *  - On a block match: fseek() to the block in the dest file and fwrite() it.
 *  - On a literal: append to a small flush buffer; fwrite() every CHUNK_SIZE bytes.
 *  - After the main loop the tail bytes are flushed.
 */
class StreamDeltaProcessor
{
    private const CHUNK = 8 * 1024 * 1024; // 8 MB I/O chunks

    /**
     * @param  string        $sourcePath  File to read (new version).
     * @param  string        $destPath    File to diff against (old version, seekable).
     * @param  BlockChecksums $checksums  Pre-computed checksums of $destPath blocks.
     * @param  int           $blockSize   Block size used when building $checksums.
     * @param  string        $outputPath  Temp file to write the result into.
     * @return int                        Number of literal bytes written (delta efficiency metric).
     */
    public function process(
        string        $sourcePath,
        string        $destPath,
        BlockChecksums $checksums,
        int           $blockSize,
        string        $outputPath,
    ): int {
        $sourceSize = (int) filesize($sourcePath);

        if ($sourceSize === 0) {
            file_put_contents($outputPath, '');
            return 0;
        }

        $srcFp = fopen($sourcePath, 'rb');
        $dstFp = fopen($destPath,   'rb');
        $outFp = fopen($outputPath, 'wb');

        $literalBytes = 0;

        // Short-circuit: source smaller than one block or no dest blocks to match.
        if ($sourceSize < $blockSize || $checksums->getBlockCount() === 0) {
            while (!feof($srcFp)) {
                $chunk = fread($srcFp, self::CHUNK);
                fwrite($outFp, $chunk);
                $literalBytes += strlen($chunk);
            }
            fclose($srcFp);
            fclose($dstFp);
            fclose($outFp);
            return $literalBytes;
        }

        $roller  = new RollingChecksum();
        $buf     = '';   // sliding source buffer
        $bufBase = 0;    // absolute source offset of $buf[0]
        $pos     = 0;    // current window start (absolute)
        $litBuf  = '';   // pending literal bytes

        // Ensure $buf covers [$absStart .. $absStart + $need - 1].
        // Trims consumed data to prevent unbounded buffer growth.
        $refill = function (int $absStart, int $need) use (&$buf, &$bufBase, $srcFp): void {
            $keep = max(0, $absStart - 1 - $bufBase);
            if ($keep > 0 && $keep < strlen($buf)) {
                $buf     = substr($buf, $keep);
                $bufBase += $keep;
            }
            while (($absStart - $bufBase + $need) > strlen($buf) && !feof($srcFp)) {
                $buf .= fread($srcFp, self::CHUNK);
            }
        };

        // Prime the buffer and roller.
        $refill($pos, $blockSize);
        $roller->init(substr($buf, 0, $blockSize));

        while ($pos <= $sourceSize - $blockSize) {
            $refill($pos, $blockSize);
            $winOff = $pos - $bufBase;
            $weak   = $roller->getValue();

            if ($checksums->hasWeak($weak)) {
                $block      = substr($buf, $winOff, $blockSize);
                $blockIndex = $checksums->findBlock($weak, md5($block));

                if ($blockIndex !== null) {
                    // Flush pending literals.
                    if ($litBuf !== '') {
                        fwrite($outFp, $litBuf);
                        $literalBytes += strlen($litBuf);
                        $litBuf = '';
                    }
                    // Copy matching block directly from dest.
                    fseek($dstFp, $blockIndex * $blockSize);
                    fwrite($outFp, fread($dstFp, $blockSize));

                    $pos += $blockSize;

                    if ($pos <= $sourceSize - $blockSize) {
                        $refill($pos, $blockSize);
                        $roller->init(substr($buf, $pos - $bufBase, $blockSize));
                    }
                    continue;
                }
            }

            // No match: emit one literal byte.
            $winOff  = $pos - $bufBase;
            $litBuf .= $buf[$winOff];

            // Flush literal buffer when it reaches chunk size.
            if (strlen($litBuf) >= self::CHUNK) {
                fwrite($outFp, $litBuf);
                $literalBytes += strlen($litBuf);
                $litBuf = '';
            }

            $pos++;

            if ($pos <= $sourceSize - $blockSize) {
                $refill($pos, $blockSize);
                $winOff = $pos - $bufBase;
                $roller->roll(
                    ord($buf[$winOff - 1]),
                    ord($buf[$winOff + $blockSize - 1]),
                );
            }
        }

        // Tail bytes that do not form a complete window.
        if ($pos < $sourceSize) {
            while (!feof($srcFp)) {
                $buf .= fread($srcFp, self::CHUNK);
            }
            $litBuf .= substr($buf, $pos - $bufBase);
        }

        if ($litBuf !== '') {
            fwrite($outFp, $litBuf);
            $literalBytes += strlen($litBuf);
        }

        fclose($srcFp);
        fclose($dstFp);
        fclose($outFp);

        return $literalBytes;
    }
}
