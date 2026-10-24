<?php

namespace App\Services\Data;

use App\Models\Period;
use Carbon\CarbonImmutable;

final readonly class Overview
{
    /**
     * @param  list<FixedItem>  $fixedItems
     * @param  list<int>  $dailySpent  budget spending per day from the period start up to today
     */
    public function __construct(
        public Period $period,
        public CarbonImmutable $today,
        public Forecast $forecast,
        public array $fixedItems,
        public int $spentToday,
        public bool $noSpendMarked,
        public array $dailySpent = [],
    ) {}

    /**
     * What is left for the month right now: income minus the fixed items and the spending so far.
     */
    public function availableNow(): int
    {
        return $this->forecast->income - $this->forecast->committed - $this->forecast->variableSpent;
    }

    /**
     * Fixed items not done yet that were due by now or are due in the next few days.
     *
     * @return list<FixedItem>
     */
    public function dueSoon(int $days = 3): array
    {
        $until = $this->today->addDays($days);

        return array_values(array_filter($this->fixedItems, fn (FixedItem $item): bool => ! $item->paid && $item->dueOn instanceof CarbonImmutable && ! $item->dueOn->greaterThan($until)));
    }

    /**
     * Variable categories, the ones closest to (or over) their budget first.
     *
     * @return list<CategoryForecast>
     */
    public function categoriesByUrgency(): array
    {
        $categories = $this->forecast->categories;

        usort($categories, fn (CategoryForecast $a, CategoryForecast $b): int => [$b->ratio(), $b->spent] <=> [$a->ratio(), $a->spent]);

        return $categories;
    }
}
