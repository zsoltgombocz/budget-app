<?php

namespace App\Services;

use App\Enums\DeviationReason;
use App\Enums\LineType;
use App\Services\Data\CategoryForecast;
use App\Services\Data\DeviationItem;
use App\Services\Data\Forecast;
use App\Services\Data\PlanDeviation;
use App\Services\Data\PlanLine;

final class ForecastService
{
    /**
     * Expected end-of-period leftover at the current spending pace.
     *
     * Fixed, transfer, loan and sinking lines always count with their planned amount, also when
     * spending was recorded in their category (the closing counts them the same way); a fixed
     * item marked as not paid this period counts as 0.
     * Variable categories count with max(plan, spent): a one-off purchase is not
     * extrapolated over the rest of the period.
     * Money moved between the pockets and this period's budget changes what the period can spend:
     * a withdrawal into the budget adds to it, a deposit from it takes off (also from the daily
     * allowance).
     *
     * @param  list<PlanLine>  $lines
     * @param  int  $income  the period's own income, without pocket moves
     * @param  array<int, int>  $spentByCategory  category id => spent amount
     * @param  array<int, string>  $categoryNames  names for spent categories without a plan line
     * @param  int  $pocketDeposits  put into pockets from this period's budget (positive)
     * @param  int  $pocketWithdrawals  taken out of pockets into this period's budget (positive)
     */
    public function forecast(
        array $lines,
        int $income,
        array $spentByCategory,
        int $totalDays,
        int $elapsedDays,
        array $categoryNames = [],
        int $pocketDeposits = 0,
        int $pocketWithdrawals = 0,
    ): Forecast {
        $baseIncome = $income;
        $income = $baseIncome + $pocketWithdrawals - $pocketDeposits;
        $committed = 0;
        $variablePlan = [];
        $committedCategories = [];
        $names = $categoryNames;

        foreach ($lines as $line) {
            if ($line->type === LineType::Variable) {
                $variablePlan[$line->categoryId] = ($variablePlan[$line->categoryId] ?? 0) + $line->planned();
                $names[$line->categoryId] = $line->categoryName;
            } else {
                $committed += $line->counted();
                $committedCategories[$line->categoryId] = true;
            }
        }

        // Spending recorded in a category that is planned as a fixed/transfer/loan/sinking line is
        // already covered by that line's planned amount, exactly as at closing; only spending in
        // categories without any plan line counts as unplanned.
        foreach (array_keys($spentByCategory) as $categoryId) {
            if (! isset($committedCategories[$categoryId])) {
                $variablePlan[$categoryId] ??= 0;
            }
        }

        $categories = [];

        foreach ($variablePlan as $categoryId => $planned) {
            $spent = $spentByCategory[$categoryId] ?? 0;

            $categories[] = new CategoryForecast(
                categoryId: $categoryId,
                categoryName: $names[$categoryId] ?? '',
                planned: $planned,
                spent: $spent,
                expected: $this->expectedSpend($planned, $spent),
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
            dailyAllowance: $this->dailyAllowance($variablePlanned + $pocketWithdrawals - $pocketDeposits, $variableSpent, $remainingDays),
            categories: $categories,
            baseIncome: $baseIncome,
            pocketDeposits: $pocketDeposits,
            pocketWithdrawals: $pocketWithdrawals,
        );
    }

    /**
     * The month against its plan: the leftover if every budget is spent in full, minus the
     * leftover the plan expects (income − every planned line, skipped ones at full amount).
     * The reasons add up to the difference: each budget overspent, each fixed item skipped
     * this month, money put into pockets and money taken from them into the budget.
     *
     * @param  list<PlanLine>  $lines  the same lines the forecast was made from
     */
    public function deviation(Forecast $forecast, array $lines): PlanDeviation
    {
        $items = [];
        $skippedTotal = 0;

        foreach ($forecast->categories as $category) {
            if ($category->spent > $category->planned) {
                $items[] = new DeviationItem(DeviationReason::Overspent, -($category->spent - $category->planned), $category->categoryName, withoutBudget: $category->planned === 0);
            }
        }

        foreach ($lines as $line) {
            if ($line->type !== LineType::Variable && $line->skipped && $line->planned() > 0) {
                $skippedTotal += $line->planned();
                $items[] = new DeviationItem(DeviationReason::Skipped, $line->planned(), $line->categoryName);
            }
        }

        if ($forecast->pocketDeposits > 0) {
            $items[] = new DeviationItem(DeviationReason::ToPockets, -$forecast->pocketDeposits);
        }

        if ($forecast->pocketWithdrawals > 0) {
            $items[] = new DeviationItem(DeviationReason::FromPockets, $forecast->pocketWithdrawals);
        }

        return new PlanDeviation(
            plannedLeftover: $forecast->baseIncome - $forecast->committed - $skippedTotal - $forecast->variablePlanned,
            expectedLeftover: $forecast->expectedLeftover,
            items: $items,
        );
    }

    public function expectedSpend(int $planned, int $spent): int
    {
        return max($planned, $spent);
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
