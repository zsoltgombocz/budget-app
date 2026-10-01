<?php

namespace App\Services\Data;

/**
 * How a period's leftover is split at closing.
 */
final readonly class Allocation
{
    public function __construct(
        public int $leftover,
        public int $toReserve,
        public int $toSurplus,
        public int $fromReserve,
        public int $uncovered,
    ) {}
}
