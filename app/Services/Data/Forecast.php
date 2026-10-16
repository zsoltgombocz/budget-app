<?php

namespace App\Services\Data;

final readonly class Forecast
{
    /**
     * $income is what the period can spend: its own income plus the money taken from pockets
     * into the budget, minus the money put into pockets from it.
     *
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
        /** The period's own income, without the pocket moves ($income includes them). */
        public int $baseIncome = 0,
        public int $pocketDeposits = 0,
        public int $pocketWithdrawals = 0,
    ) {}
}
