<?php

use App\Support\Tour;
use Carbon\CarbonImmutable;

it('explains the leftover with the user’s own numbers, naming the unspent budget only when there is one', function (): void {
    $steps = Tour::today(leftover: 263_560, unspentBudget: 126_550, ifSpent: 137_010, daily: 8_436, remainingDays: 15, variableSpent: 23_450, variablePlanned: 150_000);
    $text = fn (string $id): string => implode(' ', collect($steps)->firstWhere('id', $id)['body']);

    expect(array_column($steps, 'id'))->toBe(['leftover', 'if-spent', 'daily', 'record', 'no-spend', 'due', 'categories', 'fixed', 'tabs'])
        ->and($text('leftover'))->toContain(money(263_560))->toContain(money(126_550))
        ->and($text('if-spent'))->toContain(money(137_010))
        ->and($text('daily'))->toContain(money(8_436))->toContain('15')
        ->and($text('categories'))->toContain(money(23_450))->toContain(money(150_000));

    $spent = Tour::today(leftover: 100, unspentBudget: 0, ifSpent: 100, daily: 0, remainingDays: 1, variableSpent: 0, variablePlanned: 0);

    expect($spent[0]['body'][2])->toBe(__('It goes down with every spending you record.'));
});

it('tells when the month can be closed', function (): void {
    app()->setLocale('hu');

    expect(Tour::month(leftover: 1_000, closableFrom: CarbonImmutable::create(2026, 10, 25))[1]['body'][0])->toContain('okt 25-től')
        ->and(Tour::month(leftover: 1_000, closableFrom: null)[1]['body'][0])->toBe(__('You can close the month in its last 7 days: now is the time.'));
});

it('walks the plan, the pockets and the month with their own steps', function (): void {
    expect(array_column(Tour::plan(income: 500_000, expenses: 350_000, left: 150_000), 'id'))->toBe(['plan-summary', 'plan-income', 'plan-fixed', 'plan-variable', 'plan-sinking', 'plan-edit', 'plan-month-end'])
        ->and(implode(' ', Tour::plan(income: 500_000, expenses: 350_000, left: 150_000)[0]['body']))->toContain(money(150_000))
        ->and(array_column(Tour::pockets(saved: 10_000), 'id'))->toBe(['pockets-header', 'pocket-card', 'pocket-deposit', 'pocket-withdraw', 'loans'])
        ->and(array_column(Tour::month(leftover: 0, closableFrom: null), 'id'))->toBe(['month-now', 'month-close', 'month-list']);
});
