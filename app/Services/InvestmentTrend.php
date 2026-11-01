<?php

namespace App\Services;

use App\Services\Data\Curve;

/**
 * An investment account's value over time for its card, drawn like the Today curve: the value
 * day by day (solid) and where it started as a dashed line, so the gain or loss shows at a glance.
 */
final class InvestmentTrend
{
    private const float TOP = 30.0;

    private const float BOTTOM = 100.0;

    /**
     * @param  list<int>  $values  oldest first, at least two
     */
    public function build(array $values): ?Curve
    {
        if (count($values) < 2) {
            return null;
        }

        $high = max($values);
        $low = min($values);
        $scale = (self::BOTTOM - self::TOP) / max(1, $high - $low);
        $y = fn (int $value): float => $high === $low ? (self::TOP + self::BOTTOM) / 2 : self::TOP + ($high - $value) * $scale;
        $step = (Curve::WIDTH - 14) / (count($values) - 1);

        $points = [];

        foreach ($values as $index => $value) {
            $points[] = [7 + $index * $step, $y($value)];
        }

        [$endX, $endY] = $points[array_key_last($points)];

        return new Curve($points, $endX, $endY, $y($values[0]));
    }
}
