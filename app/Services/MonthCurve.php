<?php

namespace App\Services;

use App\Services\Data\Curve;

/**
 * Scales the month for the Today card. The top is what the month starts with after the fixed
 * items (income − fixed), the bottom is zero, or the lowest point when the month goes negative.
 */
final class MonthCurve
{
    private const float TOP = 8.0;

    private const float BOTTOM = 110.0;

    /**
     * @param  list<int>  $dailySpent  spending per day from the period start up to today
     */
    public function build(int $start, array $dailySpent, int $totalDays, int $expectedEnd, int $plannedEnd): Curve
    {
        $values = [$start];
        $left = $start;

        foreach ($dailySpent as $spent) {
            $left -= $spent;
            $values[] = $left;
        }

        $high = max($start, 1);
        $low = min(0, $expectedEnd, $plannedEnd, ...$values);
        $scale = (self::BOTTOM - self::TOP) / max(1, $high - $low);
        $y = fn (int $value): float => self::TOP + ($high - $value) * $scale;
        $step = (Curve::WIDTH - 4) / max(1, $totalDays);

        $points = [];

        foreach ($values as $day => $value) {
            $points[] = [round(min($day, $totalDays) * $step, 1), round($y($value), 1)];
        }

        return new Curve(
            points: $points,
            endX: round($totalDays * $step, 1),
            endY: round($y($expectedEnd), 1),
            planY: round($y($plannedEnd), 1),
        );
    }
}
