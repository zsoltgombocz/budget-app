<?php

use App\Services\DailyChart;
use App\Services\Data\DailyBar;

it('scales the days to the biggest one and marks today, the no-spend days and the days to come', function (): void {
    $bars = new DailyChart()->bars(dailySpent: [10_000, 0, 0, 5_000], noSpendDays: [1], totalDays: 6, includesToday: true);

    expect(array_map(fn (DailyBar $bar): string => $bar->kind, $bars))->toBe(['spent', 'no_spend', 'empty', 'today', 'future', 'future'])
        ->and($bars[0]->height)->toBe(84.0)
        ->and($bars[0]->y)->toBe(6.0)
        ->and($bars[3]->height)->toBe(42.0)
        ->and($bars[1]->height)->toBe(2.0);
});

it('does not mark a last day as today in a past period', function (): void {
    $bars = new DailyChart()->bars(dailySpent: [1_000, 2_000], noSpendDays: [], totalDays: 2, includesToday: false);

    expect($bars[1]->kind)->toBe('spent');
});

it('averages the days so far', function (): void {
    expect(new DailyChart()->average([10_000, 0, 5_000]))->toBe(5_000)
        ->and(new DailyChart()->average([]))->toBe(0)
        ->and(new DailyChart()->average([0, 0, 0, 6_000, 0]))->toBe(3_000);
});
