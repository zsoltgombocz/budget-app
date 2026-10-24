<?php

use App\Services\MonthCurve;

it('starts at the top, falls with each day of spending and ends at the expected leftover', function (): void {
    $curve = new MonthCurve()->build(start: 100_000, dailySpent: [10_000, 0, 40_000], totalDays: 30, expectedEnd: 20_000, plannedEnd: 30_000);

    expect($curve->points[0])->toBe([0.0, 8.0])
        ->and($curve->points)->toHaveCount(4)
        ->and($curve->today())->toBe([32.2, 59.0])
        ->and($curve->endX)->toBe(322.0)
        ->and($curve->endY)->toBe(89.6)
        ->and($curve->planY)->toBe(79.4)
        ->and($curve->line())->toStartWith('M0.0,8.0 L10.7,18.2')
        ->and($curve->area())->toEndWith('L32.2,120 L0,120 Z');
});

it('makes room below zero when the month goes negative', function (): void {
    $curve = new MonthCurve()->build(start: 50_000, dailySpent: [60_000], totalDays: 10, expectedEnd: -50_000, plannedEnd: 10_000);

    expect($curve->endY)->toBe(110.0)
        ->and($curve->today()[1])->toBeLessThan(110.0);
});
