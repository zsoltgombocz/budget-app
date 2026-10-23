<?php

use App\Services\Goals;
use App\Services\Watching;
use App\Services\WeeklySummary;
use Carbon\CarbonImmutable;

it('notes a watched category from 80% and turns amber once over', function (): void {
    [$restaurant, $fun, $over] = new Watching()->states(
        [1 => ['name' => 'Étterem és kávé', 'amount' => 30_000], 2 => ['name' => 'Szórakozás', 'amount' => 15_000], 3 => ['name' => 'Ruha', 'amount' => 10_000]],
        [1 => 24_000, 2 => 6_000, 3 => 12_000],
    );

    expect($restaurant->percent())->toBe(80)
        ->and($restaurant->left())->toBe(6_000)
        ->and($restaurant->isNearing())->toBeTrue()
        ->and($fun->isNearing())->toBeFalse()
        ->and($over->isOver())->toBeTrue()
        ->and($over->isNearing())->toBeFalse()
        ->and($over->left())->toBe(-2_000);
});

it('watches two categories on the base plan and any number on Plusz', function (): void {
    $watching = new Watching;

    expect($watching->canWatchMore(1, hasPlusz: false))->toBeTrue()
        ->and($watching->canWatchMore(2, hasPlusz: false))->toBeFalse()
        ->and($watching->canWatchMore(9, hasPlusz: true))->toBeTrue();
});

it('says how far under the allowance the week stayed', function (): void {
    $week = new WeeklySummary()->summarize(array_fill(0, 7, 5_000), ['Bolt' => 12_300, 'Étterem és kávé' => 9_900, 'Közlekedés' => 10_000], streak: 12);

    expect($week->spent)->toBe(32_200)
        ->and($week->isUnder())->toBeTrue()
        ->and($week->perDayUnder())->toBe(400)
        ->and($week->topCategory)->toBe('Bolt')
        ->and($week->topCategoryAmount)->toBe(12_300);
});

it('says how far over the allowance the week went', function (): void {
    $week = new WeeklySummary()->summarize(array_fill(0, 7, 5_000), ['Étterem és kávé' => 37_590], streak: 19);

    expect($week->isUnder())->toBeFalse()
        ->and($week->perDayUnder())->toBe(-370);
});

it('has no top category in a week without spending', function (): void {
    expect(new WeeklySummary()->summarize(array_fill(0, 7, 5_000), [], 3)->topCategory)->toBeNull();
});

it('forecasts when a goal is reached', function (): void {
    $forecast = new Goals()->forecast(target: 300_000, balance: 100_000, monthly: 25_000, thisMonth: CarbonImmutable::create(2026, 10, 15));

    expect($forecast->missing)->toBe(200_000)
        ->and($forecast->months)->toBe(8)
        ->and($forecast->reachedIn->format('Y-m'))->toBe('2027-05');
});

it('has no date for a goal nothing is saved for monthly', function (): void {
    $forecast = new Goals()->forecast(300_000, 100_000, 0, CarbonImmutable::create(2026, 10, 1));

    expect($forecast->months)->toBeNull()
        ->and($forecast->reachedIn)->toBeNull()
        ->and($forecast->isReached())->toBeFalse();
});

it('suggests the monthly amount for a deadline', function (): void {
    // October 2026 – June 2027 is 9 months.
    expect(new Goals()->monthlyFor(300_000, 100_000, CarbonImmutable::create(2026, 10, 1), CarbonImmutable::create(2027, 6, 1)))->toBe(22_223);
});
