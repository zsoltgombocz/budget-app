<?php

namespace App\Services\Data;

use Carbon\CarbonImmutable;

final readonly class Milestone
{
    public function __construct(
        public string $key,
        public string $title,
        public string $icon,
        public ?CarbonImmutable $achievedAt,
        /** 0–100, for the ones that can be counted (streaks, the biggest goal). */
        public int $progress,
        /** "12 / 30" or "68%" while not reached. */
        public ?string $detail,
    ) {}
}
