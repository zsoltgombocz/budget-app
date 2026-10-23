<?php

namespace App\Services\Data;

/**
 * Sunday's three lines: the week against its allowance, where most of it went, the streak.
 */
final readonly class WeekSummary
{
    public function __construct(
        public int $spent,
        /** The daily allowances of the week's days added up. */
        public int $allowance,
        public int $days,
        public ?string $topCategory,
        public int $topCategoryAmount,
        public int $streak,
    ) {}

    /**
     * Per day under (positive) or over (negative) the allowance, rounded towards zero.
     */
    public function perDayUnder(): int
    {
        return intdiv($this->allowance - $this->spent, max(1, $this->days));
    }

    public function isUnder(): bool
    {
        return $this->spent <= $this->allowance;
    }
}
