<?php

namespace App\Services;

use App\Services\Data\GoalForecast;
use Carbon\CarbonImmutable;

/**
 * Goals grow only by what the user does: a "Félretettem" tap, a closing decision, or setting
 * "Most ennyi van rajta". These are the numbers around that.
 */
final class Goals
{
    /**
     * When a goal is reached saving $monthly a month, counting the current month as the first.
     */
    public function forecast(int $target, int $balance, int $monthly, CarbonImmutable $thisMonth): GoalForecast
    {
        $missing = max(0, $target - $balance);

        if ($missing === 0) {
            return new GoalForecast(0, 0, $thisMonth->startOfMonth());
        }

        if ($monthly <= 0) {
            return new GoalForecast($missing, null, null);
        }

        $months = intdiv($missing + $monthly - 1, $monthly);

        return new GoalForecast($missing, $months, $thisMonth->startOfMonth()->addMonths($months - 1));
    }

    /**
     * The monthly amount that reaches the goal by the deadline month, the current month included.
     */
    public function monthlyFor(int $target, int $balance, CarbonImmutable $thisMonth, CarbonImmutable $deadline): int
    {
        $missing = max(0, $target - $balance);
        $months = max(1, (int) $thisMonth->startOfMonth()->diffInMonths($deadline->startOfMonth()) + 1);

        return intdiv($missing + $months - 1, $months);
    }
}
