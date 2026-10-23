<?php

namespace App\Services;

use App\Services\Data\WeekSummary;

final class WeeklySummary
{
    /**
     * @param  list<int>  $dailyAllowances  each day's allowance in the week (as it was that morning)
     * @param  array<string, int>  $spentByCategory  category name => spent in the week
     */
    public function summarize(array $dailyAllowances, array $spentByCategory, int $streak): WeekSummary
    {
        arsort($spentByCategory);
        $top = array_key_first($spentByCategory);

        return new WeekSummary(
            spent: array_sum($spentByCategory),
            allowance: array_sum($dailyAllowances),
            days: count($dailyAllowances),
            topCategory: $top !== null && $spentByCategory[$top] > 0 ? (string) $top : null,
            topCategoryAmount: $top !== null ? $spentByCategory[$top] : 0,
            streak: $streak,
        );
    }
}
