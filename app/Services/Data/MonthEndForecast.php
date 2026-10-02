<?php

namespace App\Services\Data;

/**
 * What the plan leaves at month end and where it would go: the plan without the monthly
 * reserve saving, that saving, the leftover, and its split by the leftover rule.
 */
final readonly class MonthEndForecast
{
    public function __construct(
        public int $income,
        public int $planned,
        public int $reserveMonthly,
        public int $leftover,
        public Allocation $allocation,
        public bool $hasReserve,
    ) {}

    /**
     * Everything that lands in the reserve in a month: the planned saving plus its share of the leftover.
     */
    public function toReserveTotal(): int
    {
        return $this->reserveMonthly + $this->allocation->toReserve;
    }
}
