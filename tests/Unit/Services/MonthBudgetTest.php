<?php

use App\Enums\Pace;
use App\Services\Data\MonthState;
use App\Services\MonthBudget;

/**
 * The design's October: income 450 000, fixed 250 000 + savings 45 000, 31 days.
 */
function october(int $spentBeforeToday = 0, int $spentToday = 0, int $daysLeft = 31, int $extraIncome = 0, int $carriedOver = 0): MonthState
{
    return new MonthBudget()->state(
        income: 450_000,
        fixedTotal: 295_000,
        extraIncome: $extraIncome,
        carriedOver: $carriedOver,
        spentBeforeToday: $spentBeforeToday,
        spentToday: $spentToday,
        totalDays: 31,
        daysLeft: $daysLeft,
    );
}

it('takes every fixed line, savings included, off the income', function (): void {
    $state = october();

    expect($state->spendable)->toBe(155_000)
        ->and($state->remaining)->toBe(155_000)
        ->and($state->dailyAllowance)->toBe(5_000)
        ->and($state->pace)->toBe(Pace::Good);
});

it('spreads what was left this morning over the days left, today included', function (): void {
    $state = october(spentBeforeToday: 70_000, spentToday: 2_000, daysLeft: 17);

    expect($state->remaining)->toBe(83_000)
        ->and($state->dailyAllowance)->toBe(5_000)
        ->and($state->todayLeft)->toBe(3_000)
        ->and($state->tomorrowAllowance)->toBe(5_187);
});

it('keeps today\'s allowance when spending over it and lowers tomorrow\'s', function (): void {
    $state = october(spentBeforeToday: 70_000, spentToday: 7_400, daysLeft: 17);

    expect($state->dailyAllowance)->toBe(5_000)
        ->and($state->remaining)->toBe(77_600)
        ->and($state->isOverToday())->toBeTrue()
        ->and($state->overToday())->toBe(2_400)
        ->and($state->tomorrowAllowance)->toBe(4_850);
});

it('counts extra income at once in the allowance', function (): void {
    $state = october(spentBeforeToday: 70_000, daysLeft: 17, extraIncome: 15_000);

    expect($state->remaining)->toBe(100_000)
        ->and($state->dailyAllowance)->toBe(5_882);
});

it('adds money carried over from the previous month', function (): void {
    $state = new MonthBudget()->state(450_000, 295_000, 0, 18_400, 0, 0, totalDays: 30, daysLeft: 30);

    expect($state->spendable)->toBe(173_400)
        ->and($state->dailyAllowance)->toBe(5_780);
});

it('has no allowance and asks to slow down once the month is overspent', function (): void {
    $state = october(spentBeforeToday: 160_000, spentToday: 1_000, daysLeft: 3);

    expect($state->remaining)->toBe(-6_000)
        ->and($state->dailyAllowance)->toBe(0)
        ->and($state->tomorrowAllowance)->toBe(0)
        ->and($state->pace)->toBe(Pace::SlowDown);
});

it('has no tomorrow on the last day', function (): void {
    expect(october(spentBeforeToday: 150_000, daysLeft: 1)->tomorrowAllowance)->toBeNull();
});

it('rates the pace against the share of the month passed', function (Pace $expected, int $spent, int $elapsedDays): void {
    expect(new MonthBudget()->pace(155_000, $spent, 31, $elapsedDays))->toBe($expected);
})->with([
    'on the plan' => [Pace::Good, 75_000, 15],
    'a bit fast' => [Pace::Fast, 90_000, 15],
    'too fast' => [Pace::SlowDown, 110_000, 15],
    'all spent' => [Pace::SlowDown, 155_000, 20],
]);

it('rejects days that cannot be', function (): void {
    october(daysLeft: 32);
})->throws(InvalidArgumentException::class);
