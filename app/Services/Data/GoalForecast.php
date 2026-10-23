<?php

namespace App\Services\Data;

use Carbon\CarbonImmutable;

final readonly class GoalForecast
{
    public function __construct(
        public int $missing,
        /** Months of saving still needed; null when nothing is saved for it monthly. */
        public ?int $months,
        /** The month it is reached in; null when it is not saved for monthly. */
        public ?CarbonImmutable $reachedIn,
    ) {}

    public function isReached(): bool
    {
        return $this->missing <= 0;
    }
}
