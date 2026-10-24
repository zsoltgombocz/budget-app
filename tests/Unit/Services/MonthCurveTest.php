<?php

use App\Services\MonthCurve;

it('starts at the top, falls with each day of spending and ends at the expected leftover', function (): void {
    $curve = new MonthCurve()->build(start: 100_000, dailySpent: [10_000, 0, 40_000], totalDays: 30, expectedEnd: 20_000, plannedEnd: 30_000);

    expect($curve->points[0])->toBe([0.0, 30.0])
        ->and($curve->points)->toHaveCount(4)
        ->and($curve->today())->toBe([31.2, 65.0])
        ->and($curve->endX)->toBe(312.0)
        ->and($curve->endY)->toBe(86.0)
        ->and($curve->planY)->toBe(79.0)
        ->and($curve->line())->toStartWith('M0.0,30.0 L10.4,37.0')
        ->and($curve->area())->toEndWith('L31.2,120 L0,120 Z');
});

it('makes room below zero when the month goes negative', function (): void {
    $curve = new MonthCurve()->build(start: 50_000, dailySpent: [60_000], totalDays: 10, expectedEnd: -50_000, plannedEnd: 10_000);

    expect($curve->endY)->toBe(100.0)
        ->and($curve->today()[1])->toBeLessThan(100.0);
});

it('keeps a flat month below the label room at the top', function (): void {
    $curve = new MonthCurve()->build(start: 100_000, dailySpent: [0, 0], totalDays: 30, expectedEnd: 100_000, plannedEnd: 100_000);

    expect($curve->today()[1])->toBe(MonthCurve::TOP)
        ->and($curve->planY)->toBe(MonthCurve::TOP);
});
