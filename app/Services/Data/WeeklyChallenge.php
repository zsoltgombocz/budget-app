<?php

namespace App\Services\Data;

final readonly class WeeklyChallenge
{
    public function __construct(
        /** no_spend: days marked "Ma nem költöttem"; days: days written in. */
        public string $kind,
        public int $target,
        public int $done,
        public bool $completed,
    ) {}
}
