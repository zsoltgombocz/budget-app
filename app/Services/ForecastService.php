<?php

namespace App\Services;

use App\Enums\LineType;
use App\Services\Data\CategoryForecast;
use App\Services\Data\Forecast;
use App\Services\Data\PlanLine;

final class ForecastService
{
    /**
     * Before this day of the period the plan is trusted over the linear projection.
     */
    public const int PROJECTION_START_DAY = 5;

    /**
     * Expected end-of-period leftover at the current spending pace.
     *
     * Fixed, transfer, loan and sinking lines always count with their planned amount.
     * Variable categories count with max(plan, spent, linear projection) from day 5 on,
     * and with max(plan, spent) before that.
     *
     * @param  list<PlanLine>  $lines
     * @param  array<int, int>  $spentByCategory  category id => spent amount
     * @param  array<int, string>  $categoryNames  names for spent categories without a plan line
     */
    public function forecast(
        array $lines,
        int $income,
        array $spentByCategory,
        int $totalDays,
        int $elapsedDays,
        array $categoryNames = [],
    ): Forecast {
        $committed = 0;
        $variablePlan = [];
        $names = $categoryNames;

        foreach ($lines as $line) {
            if ($line->type === LineType::Variable) {
                $variablePlan[$line->categoryId] = ($variablePlan[$line->categoryId] ?? 0) + $line->planned();
                $names[$line->categoryId] = $line->categoryName;
            } else {
                $committed += $line->planned();
            }
        }

        foreach (array_keys($spentByCategory) as $categoryId) {
            $variablePlan[$categoryId] ??= 0;
        }

        $categories = [];

        foreach ($variablePlan as $categoryId => $planned) {
            $spent = $spentByCategory[$categoryId] ?? 0;

            $categories[] = new CategoryForecast(
                categoryId: $categoryId,
                categoryName: $names[$categoryId] ?? '',
                planned: $planned,
                spent: $spent,
                expected: $this->expectedSpend($planned, $spent, $totalDays, $elapsedDays),
            );
        }

        $variablePlanned = array_sum($variablePlan);
        $variableSpent = array_sum(array_map(fn (CategoryForecast $c): int => $c->spent, $categories));
        $variableExpected = array_sum(array_map(fn (CategoryForecast $c): int => $c->expected, $categories));
        $remainingDays = max(0, $totalDays - $elapsedDays + 1);

        return new Forecast(
            income: $income,
            committed: $committed,
            variablePlanned: $variablePlanned,
            variableSpent: $variableSpent,
            variableExpected: $variableExpected,
            plannedLeftover: $income - $committed - $variablePlanned,
            expectedLeftover: $income - $committed - $variableExpected,
            remainingDays: $remainingDays,
            dailyAllowance: $this->dailyAllowance($variablePlanned, $variableSpent, $remainingDays),
            categories: $categories,
        );
    }

    public function expectedSpend(int $planned, int $spent, int $totalDays, int $elapsedDays): int
    {
        $expected = max($planned, $spent);

        if ($elapsedDays < self::PROJECTION_START_DAY) {
            return $expected;
        }

        $projection = (int) round($spent * $totalDays / $elapsedDays);

        return max($expected, $projection);
    }

    /**
     * What is left of the variable budget, spread evenly over the remaining days (today included).
     */
    public function dailyAllowance(int $variablePlanned, int $variableSpent, int $remainingDays): int
    {
        if ($remainingDays <= 0) {
            return 0;
        }

        return intdiv(max(0, $variablePlanned - $variableSpent), $remainingDays);
    }
}
