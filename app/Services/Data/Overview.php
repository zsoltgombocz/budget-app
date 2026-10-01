<?php

namespace App\Services\Data;

use App\Models\Period;
use Carbon\CarbonImmutable;

final readonly class Overview
{
    /**
     * @param  list<FixedItem>  $fixedItems
     */
    public function __construct(
        public Period $period,
        public CarbonImmutable $today,
        public Forecast $forecast,
        public array $fixedItems,
        public int $spentToday,
        public bool $noSpendMarked,
    ) {}

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
