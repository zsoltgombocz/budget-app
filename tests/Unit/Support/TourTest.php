<?php

use App\Enums\LineType;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\Loan;
use App\Models\Pocket;
use App\Models\User;
use App\Support\Tour;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  list<array{id: string, title: string, body: list<string>}>  $steps
 */
function tourText(array $steps, string $id): string
{
    return implode(' ', collect($steps)->firstWhere('id', $id)['body'] ?? []);
}

function todayTour(array $overrides = []): array
{
    return Tour::today(...[
        'leftover' => 263_560,
        'unspentBudget' => 126_550,
        'ifSpent' => 137_010,
        'daily' => 8_436,
        'remainingDays' => 15,
        'variableSpent' => 23_450,
        'variablePlanned' => 150_000,
        'committedTypes' => [LineType::Fixed],
        ...$overrides,
    ]);
}

it('walks Today in six steps with the user’s own numbers', function (): void {
    $steps = todayTour();

    expect(array_column($steps, 'id'))->toBe(['leftover', 'if-spent', 'daily', 'record', 'categories', 'fixed'])
        ->and(tourText($steps, 'leftover'))->toContain(money(263_560))->toContain(money(126_550))
        ->and(tourText($steps, 'if-spent'))->toContain(money(137_010))
        ->and(tourText($steps, 'daily'))->toContain(money(8_436))->toContain('15')
        ->and(tourText($steps, 'categories'))->toContain(money(23_450))->toContain(money(150_000));
});

it('leaves out the steps that do not apply and names only the kinds of items the user has', function (): void {
    $steps = todayTour(['hasCategories' => false, 'hasFixed' => false, 'canMarkNoSpend' => false, 'committedTypes' => []]);

    expect(array_column($steps, 'id'))->toBe(['leftover', 'if-spent', 'daily', 'record'])
        ->and(tourText($steps, 'record'))->not->toContain('I didn’t spend today')
        ->and(tourText($steps, 'leftover'))->not->toContain('taken off in full');

    expect(tourText(todayTour(), 'leftover'))->toContain('Your fixed items are already taken off in full.')->not->toContain('transfers')->not->toContain('loan')
        ->and(tourText(todayTour(['committedTypes' => [LineType::Loan, LineType::Fixed, LineType::Sinking]]), 'leftover'))
        ->toContain('Your fixed items, your loan installments and your monthly savings are already taken off in full.')
        ->and(tourText(todayTour(['pocketDeposits' => 10_000]), 'leftover'))->toContain(money(10_000).' you put into pockets')
        ->and(tourText(todayTour(['hasUnbudgeted' => true]), 'categories'))->toContain('no budget')
        ->and(tourText(todayTour(['unspentBudget' => 0]), 'leftover'))->not->toContain('not spent yet');
});

it('describes the figures as the services compute them', function (): void {
    $steps = todayTour();

    // ForecastService: deposits, withdrawals and skipped items move "if you spend every budget", not only overspending.
    expect(tourText($steps, 'if-spent'))->toContain('Overspending and money put into a pocket lower it; money taken out of a pocket and a skipped fixed item raise it.')
        // dailyAllowance(): what is left spread over the remaining days, pocket moves included; spending today is not a 1:1 drop.
        ->and(tourText($steps, 'daily'))->toContain('spread evenly over the 15 days to go, today included')
        ->toContain('the days after get less')
        ->toContain('Money put into a pocket lowers it')
        // NotificationScheduler skips the evening reminder on a day marked as no-spend.
        ->and(tourText($steps, 'record'))->toContain('no reminder comes in the evening')
        // The tick and the skip as the first rules of the fixed items.
        ->and(collect($steps)->firstWhere('id', 'fixed')['body'][0])->toContain('only a reminder')
        ->and(tourText($steps, 'fixed'))->toContain('counts as 0')
        ->and(tourText(todayTour(['remainingDays' => 1]), 'daily'))->toContain('this is the last day');

    $pockets = Tour::pockets(saved: 10_000);

    // A deposit lowers the daily amount too (ForecastService::forecast), so the tour must not say the budgets stay as they are.
    expect(tourText($pockets, 'pocket-deposit'))->toContain('the daily amount gets smaller')->not->toContain('do not change')
        ->and(tourText($pockets, 'pocket-withdraw'))->toContain('Pay a spending');
});

it('walks the plan in three steps, naming savings in the total only when there are some', function (): void {
    $plan = Tour::plan(income: 500_000, expenses: 350_000, left: 150_000);

    expect(array_column($plan, 'id'))->toBe(['plan-summary', 'plan-variable', 'plan-month-end'])
        ->and($plan[0]['body'][0])->toContain('from its first day')
        ->and(tourText($plan, 'plan-summary'))->toContain(money(150_000))->toContain('expenses '.money(350_000))
        ->and(tourText(Tour::plan(500_000, 350_000, 150_000, hasSavings: true), 'plan-summary'))->toContain('expenses and savings '.money(350_000))
        ->and(tourText(Tour::plan(500_000, 350_000, 150_000, reserveMonthly: 5_000, reserveName: 'Reserve'), 'plan-month-end'))->toContain('Reserve: the '.money(5_000))
        ->and(array_column(Tour::plan(500_000, 350_000, 150_000, hasVariable: false), 'id'))->toBe(['plan-summary', 'plan-month-end']);
});

it('shows loans only to a user with a loan, and an empty pockets screen with one step', function (): void {
    expect(array_column(Tour::pockets(saved: 10_000), 'id'))->toBe(['pocket-card', 'pocket-deposit', 'pocket-withdraw'])
        ->and(array_column(Tour::pockets(saved: 10_000, hasLoans: true), 'id'))->toBe(['pocket-card', 'pocket-deposit', 'pocket-withdraw', 'loans'])
        ->and(array_column(Tour::pockets(saved: 0, hasPockets: false), 'id'))->toBe(['pockets-header']);
});

it('tells when the month can be closed, what happens without closing, and where to find the tour again', function (): void {
    app()->setLocale('hu');

    $month = Tour::month(leftover: 1_000, closableFrom: CarbonImmutable::create(2026, 10, 25));

    expect(array_column($month, 'id'))->toBe(['month-now', 'month-close', 'month-list'])
        ->and(tourText($month, 'month-close'))->toContain('okt 25-től')->toContain('az új hónap magától elindul')
        ->and(tourText($month, 'month-list'))->toContain('Beállítások → Így működik')->toContain('szűrhetsz kategóriára')
        ->and(tourText(Tour::month(leftover: 1_000, closableFrom: null), 'month-close'))->toContain(__('You can close this month now.'))
        ->and(tourText(Tour::month(1_000, null, hasSpending: false, hasFilters: false), 'month-list'))->not->toContain('kategóriára');
});

it('counts the steps of the screens after Today from what the user has', function (): void {
    $user = User::factory()->create();

    expect(Tour::upcoming($user))->toBe(['plan' => 2, 'pockets' => 1, 'month' => 3]);

    $groceries = Category::factory()->for($user)->create(['type' => 'variable']);
    BudgetLine::factory()->for($user)->for($groceries)->create();
    Pocket::factory()->for($user)->create();
    Loan::factory()->for($user)->create();

    expect(Tour::upcoming($user))->toBe(['plan' => 3, 'pockets' => 4, 'month' => 3]);
});
