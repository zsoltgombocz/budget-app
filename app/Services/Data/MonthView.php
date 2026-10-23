<?php

namespace App\Services\Data;

use App\Models\Period;
use Carbon\CarbonImmutable;

/**
 * Everything the 2.0 Today and Month screens show for one month on one day.
 */
final readonly class MonthView
{
    /**
     * @param  list<MonthLine>  $lines  fixed lines with their state, open ones first
     * @param  array{withDay: int, settled: int, noDay: int}  $fixedSummary
     * @param  array<int, int>  $spentByCategory  category id => spent this month
     */
    public function __construct(
        public Period $period,
        public CarbonImmutable $today,
        public MonthState $state,
        public array $lines,
        public array $fixedSummary,
        public int $income,
        public int $fixedTotal,
        public int $extraIncome,
        public array $spentByCategory,
        public bool $noSpendToday,
    ) {}

    /**
     * Lines the Today screen shows a row for (due today, or paid by hand and still open).
     *
     * @return list<MonthLine>
     */
    public function todayRows(): array
    {
        return array_values(array_filter($this->lines, fn (MonthLine $line): bool => $line->state?->showsOnToday() === true));
    }
}
