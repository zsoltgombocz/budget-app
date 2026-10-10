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

it('shows what the month would close with today, against the plan', function (): void {
    Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['amount' => 75_000]);

    $preview = resolve(PeriodCloser::class)->preview($this->user, $this->period);

    expect($preview->leftover() - $preview->plannedLeftover())->toBe(-75_000 + 60_000 + 90_000);

    $this->get(route('month'))
        ->assertOk()
        ->assertSeeInOrder(['data-test="close-now"', 'If you closed today', 'vs. the plan', money_number($preview->leftover()), '+'.money(75_000), 'Variable spending so far'], false);
});

it('does not show the close-now figures for a closed period', function (): void {
    resolve(PeriodCloser::class)->close($this->user, $this->period, $this->period->income());

    Livewire::withQueryParams(['periodus' => $this->period->id])
        ->test('pages::month')
        ->assertDontSee('data-test="close-now"', false);
});

it('does not say "so far" or point to the + button on a closed period', function (): void {
    resolve(PeriodCloser::class)->close($this->user, $this->period, $this->period->income());

    Livewire::withQueryParams(['periodus' => $this->period->id])
        ->test('pages::month')
        ->assertSee('Variable spending')
        ->assertDontSee('Variable spending so far')
        ->assertSee('No spending or “didn’t spend” day was recorded in this period.')
        ->assertDontSee('with the + button');
});
