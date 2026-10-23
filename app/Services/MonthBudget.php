<?php

namespace App\Services;

use App\Enums\Pace;
use App\Services\Data\MonthState;
use InvalidArgumentException;

/**
 * The 2.0 money model: income minus every monthly expense is what the month has, and that is all.
 *
 * Savings are an ordinary fixed line, so they are already taken off. The daily allowance is what was
 * left this morning spread over the days left (today included): spending today only lowers today's
 * "left", while extra income counts at once. There is no hidden floor or cap.
 */
final class MonthBudget
{
    /** Spending up to this share above the month's elapsed share still counts as only a bit fast. */
    private const float FAST_TOLERANCE = 0.15;

    /**
     * @param  int  $income  the month's planned (or, at closing, actual) income
     * @param  int  $fixedTotal  every fixed line counted this month, savings and loans included
     * @param  int  $extraIncome  extra income recorded this month ("Kaptam pénzt")
     * @param  int  $carriedOver  carried over from the previous month (negative when it was deducted)
     * @param  int  $spentBeforeToday  spending recorded this month before today
     * @param  int  $spentToday  spending recorded today
     * @param  int  $totalDays  days in the month
     * @param  int  $daysLeft  days left in the month, today included
     */
    public function state(
        int $income,
        int $fixedTotal,
        int $extraIncome,
        int $carriedOver,
        int $spentBeforeToday,
        int $spentToday,
        int $totalDays,
        int $daysLeft,
    ): MonthState {
        if ($totalDays < 1 || $daysLeft < 1 || $daysLeft > $totalDays) {
            throw new InvalidArgumentException("Invalid days: {$daysLeft} left of {$totalDays}.");
        }

        $spendable = $income + $extraIncome + $carriedOver - $fixedTotal;
        $spent = $spentBeforeToday + $spentToday;
        $remaining = $spendable - $spent;
        $dailyAllowance = $this->allowance($spendable - $spentBeforeToday, $daysLeft);

        return new MonthState(
            spendable: $spendable,
            spent: $spent,
            remaining: $remaining,
            daysLeft: $daysLeft,
            dailyAllowance: $dailyAllowance,
            spentToday: $spentToday,
            todayLeft: $dailyAllowance - $spentToday,
            tomorrowAllowance: $daysLeft > 1 ? $this->allowance($remaining, $daysLeft - 1) : null,
            pace: $this->pace($spendable, $spent, $totalDays, $totalDays - $daysLeft + 1),
        );
    }

    /**
     * What one day gets out of the money left: rounded down to the unit, never below zero.
     */
    public function allowance(int $left, int $days): int
    {
        return $left > 0 ? intdiv($left, max(1, $days)) : 0;
    }

    /**
     * Compares the spending with the share of the month passed by the end of today.
     */
    public function pace(int $spendable, int $spent, int $totalDays, int $elapsedDays): Pace
    {
        if ($spendable - $spent <= 0) {
            return $spent > 0 || $spendable < 0 ? Pace::SlowDown : Pace::Good;
        }

        $expected = $spendable * $elapsedDays / $totalDays;

        return match (true) {
            $spent <= $expected => Pace::Good,
            $spent <= $expected + $spendable * self::FAST_TOLERANCE => Pace::Fast,
            default => Pace::SlowDown,
        };
    }
}
