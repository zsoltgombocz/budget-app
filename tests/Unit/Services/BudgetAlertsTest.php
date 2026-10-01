<?php

use App\Services\BudgetAlerts;

it('detects crossing the 80 and 100 percent thresholds', function (int $before, int $after, ?int $expected): void {
    expect(resolve(BudgetAlerts::class)->crossedThreshold(100_000, $before, $after))->toBe($expected);
})->with([
    'below' => [10_000, 50_000, null],
    'crosses 80' => [70_000, 85_000, 80],
    'exactly 80' => [70_000, 80_000, 80],
    'already above 80' => [81_000, 90_000, null],
    'crosses 100' => [90_000, 100_000, 100],
    'jumps over both' => [10_000, 120_000, 100],
]);

it('never alerts without a budget', function (): void {
    expect(resolve(BudgetAlerts::class)->crossedThreshold(0, 0, 5_000))->toBeNull();
});
