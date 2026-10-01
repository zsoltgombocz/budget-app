<?php

namespace App\Services\Data;

final readonly class Forecast
{
    /**
     * @param  list<CategoryForecast>  $categories
     */
    public function __construct(
        public int $income,
        public int $committed,
        public int $variablePlanned,
        public int $variableSpent,
        public int $variableExpected,
        public int $plannedLeftover,
        public int $expectedLeftover,
        public int $remainingDays,
        public int $dailyAllowance,
        public array $categories,
    ) {}
}
