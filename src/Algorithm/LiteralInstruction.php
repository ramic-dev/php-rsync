<?php

declare(strict_types=1);

namespace Ramic\Rsync\Algorithm;

/**
 * Literal data that must be sent verbatim from the sender.
 */
class LiteralInstruction implements DeltaInstruction
{
    public function __construct(
        public readonly string $data,
    ) {}
}
