<?php

namespace App\Services;

use App\Services\Data\DailyBar;

/**
 * The "Napi költés" bars on the Month screen: one bar per day of the period, scaled to the
 * biggest day. No-spend days are dots, the days still to come are stubs.
 */
final class DailyChart
{
    public const int WIDTH = 326;

    public const int HEIGHT = 90;

    private const float BAR = 6.0;

    /**
     * @param  list<int>  $dailySpent  spending per day from the period start up to today (or the end)
     * @param  list<int>  $noSpendDays  0-based day indexes marked "Ma nem költöttem"
     * @return list<DailyBar>
     */
    public function bars(array $dailySpent, array $noSpendDays, int $totalDays, bool $includesToday): array
    {
        $max = max(1, ...$dailySpent);
        $step = self::WIDTH / max(1, $totalDays);
        $noSpend = array_flip($noSpendDays);
        $lastIndex = count($dailySpent) - 1;
        $bars = [];

        for ($day = 0; $day < $totalDays; $day++) {
            $x = round($day * $step + ($step - self::BAR) / 2, 1);
            $spent = $dailySpent[$day] ?? null;

            $kind = match (true) {
                $spent === null => 'future',
                $spent > 0 && $includesToday && $day === $lastIndex => 'today',
                $spent > 0 => 'spent',
                isset($noSpend[$day]) => 'no_spend',
                default => 'empty',
            };

            $height = in_array($kind, ['spent', 'today'], true) ? max(3.0, round($spent / $max * (self::HEIGHT - 6), 1)) : 2.0;

            $bars[] = new DailyBar($x, round(self::HEIGHT - $height, 1), $height, $kind);
        }

        return $bars;
    }

    /**
     * @param  list<int>  $dailySpent
     */
    public function average(array $dailySpent): int
    {
        return $dailySpent === [] ? 0 : intdiv(array_sum($dailySpent), count($dailySpent));
    }
}
