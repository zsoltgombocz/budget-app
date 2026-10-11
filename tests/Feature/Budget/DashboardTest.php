<?php

use App\Enums\Currency;
use App\Enums\PeriodMode;
use App\Models\BudgetLine;
use App\Models\BudgetSetting;
use App\Models\Category;
use App\Models\DayMark;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Data\CategoryForecast;
use App\Services\OverviewService;
use App\Services\PeriodCloser;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-11 10:00', 'Europe/Budapest'));
    $this->user = onboardedUser();
    $this->actingAs($this->user);
    $this->period = resolve(PeriodService::class)->current($this->user);
    $this->fuel = Category::query()->where('name', 'Fuel')->firstOrFail();
});

it('shows the expected leftover from the plan and the overspending so far', function (): void {
    // 75 000 spent on fuel is over the 60 000 plan, so fuel counts with 75 000 (not extrapolated by pace).
    Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['amount' => 75_000, 'occurred_on' => '2026-10-05']);

    $overview = resolve(OverviewService::class)->forUser($this->user);

    expect($overview->forecast->expectedLeftover)->toBe(500_000 - 200_000 - 75_000 - 90_000)
        ->and($overview->forecast->remainingDays)->toBe(21);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee(money_number(135_000))
        ->assertSee('Fuel')
        ->assertDontSee('data-test="fixed-items"', false);
});

it('lists fixed items with due dates and ticks them off', function (): void {
    $rent = BudgetLine::query()->whereRelation('category', 'name', 'Rent')->firstOrFail();
    $rent->update(['due_day' => 5]);

    $item = resolve(OverviewService::class)->forUser($this->user)->fixedItems[0];
    expect($item->dueOn->toDateString())->toBe('2026-10-05')
        ->and($item->isOverdue(CarbonImmutable::parse('2026-10-11')))->toBeTrue();

    Livewire::test('pages::today')->call('togglePaid', $rent->id);
    expect(resolve(OverviewService::class)->forUser($this->user)->fixedItems[0]->paid)->toBeTrue();

    Livewire::test('pages::today')->call('togglePaid', $rent->id);
    expect(resolve(OverviewService::class)->forUser($this->user)->fixedItems[0]->paid)->toBeFalse();
});

it('shows the currency next to the amount of a fixed item that is due', function (): void {
    $this->user->settings()->update(['currency' => Currency::EUR]);
    $rent = BudgetLine::query()->whereRelation('category', 'name', 'Rent')->firstOrFail();
    $rent->update(['due_day' => 11]);

    $html = $this->get(route('dashboard'))->assertOk()->getContent();
    preg_match('/data-test="due-item">.*?Rent.*?<\/button>/s', (string) $html, $row);

    expect($row[0] ?? '')->toContain(money_number($rent->amount, Currency::EUR))
        ->toContain(Currency::EUR->symbol());
});

it('finds the due date in a payday period spanning two months', function (): void {
    $period = resolve(PeriodService::class)->forDate($this->user, CarbonImmutable::parse('2026-12-20'));
    $period->update(['starts_on' => '2026-11-10', 'ends_on' => '2026-12-09']);

    $service = resolve(OverviewService::class);

    expect($service->dueDate($period->refresh(), 5)?->toDateString())->toBe('2026-12-05')
        ->and($service->dueDate($period, 31)?->toDateString())->toBe('2026-11-30')
        ->and($service->dueDate($period, null))->toBeNull();
});

it('orders categories by how close they are to their budget', function (): void {
    Transaction::factory()->for($this->user)->for($this->period)->for(Category::query()->where('name', 'Groceries')->firstOrFail())
        ->create(['amount' => 85_000, 'occurred_on' => '2026-10-02']);

    $names = array_map(fn (CategoryForecast $c): string => $c->categoryName, resolve(OverviewService::class)->forUser($this->user)->categoriesByUrgency());

    expect($names)->toBe(['Groceries', 'Fuel']);
});

it('cannot tick off another user\'s line', function (): void {
    $foreign = BudgetLine::factory()->create();

    Livewire::test('pages::today')->call('togglePaid', $foreign->id)->assertNotFound();
});

it('shows the empty state until something is recorded', function (): void {
    $this->get(route('dashboard'))->assertSee('data-test="empty-state"', false);
});

it('keeps the no-spend button visible and undoable', function (): void {
    Livewire::test('pages::today')
        ->call('markNoSpend')
        ->assertSee('data-test="no-spend-marked"', false)
        ->call('unmarkNoSpend')
        ->assertSee('data-test="no-spend"', false);

    expect(DayMark::query()->count())->toBe(0);
});

it('warns when the expected leftover is negative', function (): void {
    Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['amount' => 400_000, 'occurred_on' => '2026-10-05']);

    $this->get(route('dashboard'))->assertSee('data-test="negative-alert"', false)->assertSee('Fuel');
});

it('puts fixed items due today or overdue up top until they are done', function (): void {
    $rent = BudgetLine::query()->whereRelation('category', 'name', 'Rent')->firstOrFail();

    $rent->update(['due_day' => 20]);
    Livewire::test('pages::today')->assertDontSee('data-test="due-item"', false);

    $rent->update(['due_day' => 11]);
    Livewire::test('pages::today')
        ->assertSee('data-test="due-item"', false)
        ->assertSee('Due today')
        ->call('togglePaid', $rent->id)
        ->assertDontSee('data-test="due-item"', false);
});

it('marks a fixed item that was due earlier as late', function (): void {
    BudgetLine::query()->whereRelation('category', 'name', 'Rent')->firstOrFail()->update(['due_day' => 5]);

    Livewire::test('pages::today')->assertSee('data-test="due-item"', false)->assertSee('was due');
});

it('points to the plan instead of claiming income and fixed items are in when the plan is empty', function (): void {
    $user = User::factory()->create();
    BudgetSetting::factory()->for($user)->create(['income' => 0, 'locale' => 'en']);
    $this->actingAs($user);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-test="empty-state-no-plan"', false)
        ->assertSee('Start with your plan')
        ->assertSee(route('plan'))
        ->assertDontSee('Your income and fixed items are already in.');
});

it('leads with the leftover as things stand, the same figure as closing today', function (): void {
    Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['amount' => 75_000, 'occurred_on' => '2026-10-05']);
    $current = resolve(PeriodCloser::class)->preview($this->user, $this->period)->leftover();
    $expected = resolve(OverviewService::class)->forUser($this->user)->forecast->expectedLeftover;

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSeeInOrder(['Leftover as things stand', money_number($current), 'If you spend every budget in full: '.money($expected)], false);
});

it('labels the leftover the same way in payday mode', function (): void {
    $this->user->settings()->update(['period_mode' => PeriodMode::Payday, 'payday_day' => 10]);

    $this->get(route('dashboard'))->assertOk()->assertSee('Leftover as things stand');
});

it('explains each figure with the period\'s own numbers', function (): void {
    Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['amount' => 30_000, 'occurred_on' => '2026-10-05']);

    // 500 000 income − 200 000 rent − 30 000 spent; the unspent budgets are still in.
    // Daily: (150 000 budgets − 30 000 spent) / 21 days left including today.
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-test="explainer-leftover"', false)
        ->assertSee('data-test="explainer-if-spent"', false)
        ->assertSee('data-test="explainer-daily"', false)
        ->assertSeeInOrder(['Fixed items, transfers, installments, savings', '−'.money(200_000), 'Variable spending so far', '−'.money(30_000), money(270_000)], false)
        ->assertSeeInOrder(['Variable budgets', money(150_000), 'Spent so far', '−'.money(30_000), '21 days', 'Per day', money(intdiv(120_000, 21))], false);
});

it('keeps only the due fixed items on Today and links each budget to the month', function (): void {
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('data-test="fixed-items"', false)
        ->assertSee(route('month', ['kategoria' => $this->fuel->id]), false)
        ->assertSee('data-test="daily-record"', false);
});

it('shows the how-it-works intro once after onboarding', function (): void {
    $this->user->settings()->update(['intro_seen_at' => null]);

    Livewire::test('pages::today')
        ->assertSet('showIntro', true)
        ->call('dismissIntro')
        ->assertSet('showIntro', false);

    expect($this->user->settings()->refresh()->intro_seen_at)->not->toBeNull();

    Livewire::test('pages::today')->assertSet('showIntro', false);
});

it('reopens the intro from settings without touching when it was first seen', function (): void {
    $seen = $this->user->settings()->intro_seen_at;

    $this->get(route('settings'))->assertSee(route('dashboard', ['bemutato' => 1]), false);

    Livewire::withQueryParams(['bemutato' => 1])->test('pages::today')
        ->assertSet('showIntro', true)
        ->call('dismissIntro');

    expect($this->user->settings()->refresh()->intro_seen_at?->toDateTimeString())->toBe($seen?->toDateTimeString());
});
