<?php

namespace App\Services\Data;

final readonly class DailyBar
{
    public function __construct(
        public float $x,
        public float $y,
        public float $height,
        /** spent, today, no_spend (a dot), empty (a day without anything) or future (a stub). */
        public string $kind,
    ) {}
}
