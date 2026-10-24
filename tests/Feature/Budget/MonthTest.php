<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Services\PeriodCloser;
use App\Services\PeriodService;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = onboardedUser();
    $this->actingAs($this->user);
    $this->period = resolve(PeriodService::class)->current($this->user);
    $this->fuel = Category::query()->where('name', 'Fuel')->firstOrFail();
    $this->groceries = Category::query()->where('name', 'Groceries')->firstOrFail();
});

it('lists the period\'s spending', function (): void {
    Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['amount' => 12_345, 'note' => 'Shell']);

    $this->get(route('month'))->assertOk()->assertSee('Shell')->assertSee(money_number(12_345));
});

it('shows an explanation instead of an empty list', function (): void {
    $this->get(route('month'))->assertOk()->assertSee('data-test="empty-state"', false)->assertSee('Clean slate');
});

it('filters by category', function (): void {
    Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['note' => 'Shell']);
    Transaction::factory()->for($this->user)->for($this->period)->for($this->groceries)->create(['note' => 'Aldi']);

    Livewire::withQueryParams(['kategoria' => $this->fuel->id])
        ->test('pages::month')
        ->assertSee('Shell')
        ->assertDontSee('Aldi');
});

it('deletes an entry', function (): void {
    $transaction = Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create();

    Livewire::test('pages::month')->call('delete', $transaction->id);

    expect(Transaction::query()->count())->toBe(0);
});

it('shows the payday and no-spend days in the timeline', function (): void {
    $this->user->dayMarks()->create(['date' => now()->toDateString()]);

    $this->get(route('month'))->assertSee('Salary')->assertSee("Didn't spend");
});

it('shows the month-end leftover the same way as Today, split into fixed items, budgets and what is left', function (): void {
    Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['amount' => 75_000]);

    // Income 500 000, fixed 200 000, budgets: fuel 75 000 (over its 60 000) and groceries 90 000.
    $this->get(route('month'))
        ->assertOk()
        ->assertSeeInOrder(['data-test="month-end"', money_number(135_000), money_number(500_000), money_number(200_000), money_number(165_000), money_number(135_000), 'data-test="start-close"', 'data-test="daily-chart"'], false);
});

it('shows what was left and where it went for a closed period', function (): void {
    resolve(PeriodCloser::class)->close($this->user, $this->period, $this->period->income());

    Livewire::withQueryParams(['periodus' => $this->period->id])
        ->test('pages::month')
        ->assertDontSee('data-test="month-end"', false)
        ->assertSee('data-test="close-summary"', false);
});
