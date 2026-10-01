<?php

use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\DayMark;
use App\Models\Transaction;
use App\Services\Data\CategoryForecast;
use App\Services\OverviewService;
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

it('shows the expected leftover from the current pace', function (): void {
    // Day 11 of 31: 30 000 spent on fuel projects to 84 545, over the 60 000 plan.
    Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['amount' => 30_000, 'occurred_on' => '2026-10-05']);

    $overview = resolve(OverviewService::class)->forUser($this->user);

    expect($overview->forecast->expectedLeftover)->toBe(500_000 - 200_000 - 84_545 - 90_000)
        ->and($overview->forecast->remainingDays)->toBe(21);

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee(money_number(125_455))
        ->assertSee('Fuel')
        ->assertSee('Rent');
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
