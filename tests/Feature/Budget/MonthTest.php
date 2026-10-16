<?php

use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\Period;
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
    $this->travelTo($this->period->ends_on->setTime(18, 0));
    resolve(PeriodCloser::class)->close($this->user, $this->period, $this->period->income());

    Livewire::withQueryParams(['periodus' => $this->period->id])
        ->test('pages::month')
        ->assertDontSee('data-test="close-now"', false);
});

it('does not say "so far" or point to the + button on a closed period', function (): void {
    $this->travelTo($this->period->ends_on->setTime(18, 0));
    resolve(PeriodCloser::class)->close($this->user, $this->period, $this->period->income());

    Livewire::withQueryParams(['periodus' => $this->period->id])
        ->test('pages::month')
        ->assertSee('Variable spending')
        ->assertDontSee('Variable spending so far')
        ->assertSee('No spending or “didn’t spend” day was recorded in this period.')
        ->assertDontSee('with the + button');
});

it('saves a corrected spending from the edit sheet', function (): void {
    $transaction = Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['amount' => 12_000, 'note' => 'Shell']);
    $today = resolve(PeriodService::class)->today($this->user->settings());

    Livewire::test('pages::month')
        ->call('updateTransaction', $transaction->id, ['amount' => '8 500', 'category' => $this->groceries->id, 'date' => $today->toDateString(), 'note' => 'Aldi'])
        ->assertReturned(['ok' => true, 'errors' => []])
        ->assertDispatched('app-toast')
        ->assertSee('Aldi');

    expect($transaction->refresh()->amount)->toBe(8_500)
        ->and($transaction->category_id)->toBe($this->groceries->id)
        ->and($transaction->note)->toBe('Aldi');
});

it('returns the errors of an invalid correction and changes nothing', function (): void {
    $transaction = Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['amount' => 12_000]);
    $tomorrow = resolve(PeriodService::class)->today($this->user->settings())->addDay();

    Livewire::test('pages::month')
        ->call('updateTransaction', $transaction->id, ['amount' => '0', 'category' => $this->fuel->id, 'date' => $tomorrow->toDateString(), 'note' => null])
        ->assertReturned(fn (array $result): bool => $result['ok'] === false && isset($result['errors']['amount']) && $result['errors']['date'] === 'You cannot record spending on a future day.');

    expect($transaction->refresh()->amount)->toBe(12_000);
});

it('does not correct another user\'s spending', function (): void {
    $foreign = Transaction::factory()->create(['amount' => 5_000]);

    Livewire::test('pages::month')
        ->call('updateTransaction', $foreign->id, ['amount' => '1', 'category' => $this->fuel->id, 'date' => now()->toDateString(), 'note' => null])
        ->assertReturned(fn (array $result): bool => $result['ok'] === false);

    expect($foreign->refresh()->amount)->toBe(5_000);
});

it('offers an undo after deleting and restores the spending', function (): void {
    $transaction = Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['amount' => 12_000, 'note' => 'Shell']);

    $page = Livewire::test('pages::month')
        ->call('delete', $transaction->id)
        ->assertDispatched('app-toast', undo: 'month-restore-transaction')
        ->assertDontSee('Shell');

    expect(Transaction::query()->count())->toBe(0);

    $page->dispatch('month-restore-transaction', token: array_key_first($page->get('deleted')))->assertSee('Shell');

    $restored = Transaction::query()->sole();

    expect($restored->amount)->toBe(12_000)
        ->and($restored->client_uuid)->toBe($transaction->client_uuid)
        ->and($page->get('deleted'))->toBe([]);
});

it('does not delete spending of a closed period', function (): void {
    $transaction = Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create();
    $this->travelTo($this->period->ends_on->setTime(18, 0));
    resolve(PeriodCloser::class)->close($this->user, $this->period, $this->period->income());

    Livewire::withQueryParams(['periodus' => $this->period->id])->test('pages::month')->call('delete', $transaction->id);

    expect(Transaction::query()->count())->toBe(1);
});

it('offers the period\'s categories as a filter on the page', function (): void {
    Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['note' => 'Shell']);
    Transaction::factory()->for($this->user)->for($this->period)->for($this->groceries)->create(['note' => 'Aldi']);

    Livewire::test('pages::month')
        ->assertSee('data-test="filter-'.$this->fuel->id.'"', false)
        ->assertSee('data-test="filter-'.$this->groceries->id.'"', false)
        ->set('categoryId', $this->groceries->id)
        ->assertSee('Aldi')
        ->assertDontSee('Shell')
        ->assertDontSee('Salary');
});

it('searches notes, categories and amounts', function (): void {
    Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['amount' => 12_500, 'note' => 'Shell']);
    Transaction::factory()->for($this->user)->for($this->period)->for($this->groceries)->create(['amount' => 3_000, 'note' => 'Aldi']);

    Livewire::test('pages::month')
        ->set('search', 'she')->assertSee('Shell')->assertDontSee('Aldi')
        ->set('search', '12 500')->assertSee('Shell')->assertDontSee('Aldi')
        ->set('search', 'grocer')->assertSee('Aldi')->assertDontSee('Shell');
});

it('shows a no-results state with a way to clear the filters', function (): void {
    Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['note' => 'Shell']);

    Livewire::test('pages::month')
        ->set('search', 'nothing like this')
        ->assertSee('data-test="no-results"', false)
        ->assertDontSee('data-test="empty-state"', false)
        ->call('clearFilters')
        ->assertSee('Shell')
        ->assertDontSee('data-test="no-results"', false);
});

it('shows plan against actual per category on a closed month, with the overspend in words', function (): void {
    Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['amount' => 110_000, 'occurred_on' => $this->period->starts_on]);
    Transaction::factory()->for($this->user)->for($this->period)->for($this->groceries)->create(['amount' => 60_000, 'occurred_on' => $this->period->starts_on]);
    $this->travelTo($this->period->ends_on->setTime(18, 0));
    resolve(PeriodCloser::class)->close($this->user, $this->period, $this->period->income());

    Livewire::withQueryParams(['periodus' => $this->period->id])
        ->test('pages::month')
        ->assertSee('Variable spending total')
        ->assertSee(money(20_000).' over the budget')
        ->assertSeeInOrder(['data-test="closed-breakdown"', 'data-test="over-budget-row"', 'Fuel', money_number(60_000), money_number(110_000), '+'.money_number(50_000), 'Groceries', money_number(-30_000)], false)
        ->assertDontSee('data-test="fixed-items"', false);
});

it('has no plan against actual on an open month', function (): void {
    Livewire::test('pages::month')->assertDontSee('data-test="closed-breakdown"', false);
});

it('says so when the requested month is not the user\'s', function (): void {
    $foreign = Period::factory()->create();

    Livewire::withQueryParams(['periodus' => $foreign->id])
        ->test('pages::month')
        ->assertSet('periodId', $this->period->id)
        ->assertSee('data-test="period-not-found"', false)
        ->assertSee('That month was not found, so the current one is shown.');
});

it('shows no "month not found" notice without a requested month', function (): void {
    Livewire::test('pages::month')->assertDontSee('data-test="period-not-found"', false);
});

it('ticks a fixed item with an undo and keeps the list order', function (): void {
    $rent = Category::query()->where('name', 'Rent')->firstOrFail();
    $insurance = Category::factory()->for($this->user)->create(['name' => 'Insurance', 'type' => 'fixed', 'sort' => 9]);
    $rentLine = BudgetLine::query()->where('category_id', $rent->id)->firstOrFail();
    BudgetLine::factory()->for($this->user)->for($insurance)->create(['amount' => 10_000]);

    $page = Livewire::test('pages::month')
        ->assertSeeInOrder(['data-test="fixed-items"', 'role="checkbox"', 'aria-checked="false"', 'Rent', 'Insurance'], false)
        ->call('togglePaid', $rentLine->id)
        ->assertDispatched('app-toast', undo: 'month-undo-paid');

    // The ticked item stays first although ticked items sort last on a fresh page.
    expect($page->html())->toMatch('/data-test="fixed-items".*aria-checked="true".*Rent.*aria-checked="false".*Insurance/s')
        ->and($this->period->lineStatuses()->whereNotNull('paid_on')->count())->toBe(1);

    $page->dispatch('month-undo-paid', budgetLineId: $rentLine->id, paid: false)
        ->dispatch('month-undo-paid', budgetLineId: $rentLine->id, paid: false);

    expect($this->period->lineStatuses()->whereNotNull('paid_on')->count())->toBe(0);
});

it('marks the arrow without a neighbouring month as disabled', function (): void {
    Livewire::test('pages::month')
        ->assertSeeInOrder(['aria-disabled="true"', 'data-test="previous-period"', 'aria-disabled="true"', 'data-test="next-period"'], false)
        ->assertDontSee('showPeriod(', false);

    $previous = resolve(PeriodService::class)->forDate($this->user, $this->period->starts_on->subDay());

    Livewire::test('pages::month')->assertSee('showPeriod('.$previous->id.')', false);
});

it('folds the fixed items into a summary row that counts what is done and what is due', function (): void {
    $this->travelTo($this->period->starts_on->addDays(14)->setTime(12, 0));
    $rent = Category::query()->where('name', 'Rent')->firstOrFail();
    $rentLine = BudgetLine::query()->where('category_id', $rent->id)->firstOrFail();
    $rentLine->update(['due_day' => 10]);
    $insurance = Category::factory()->for($this->user)->create(['name' => 'Insurance', 'type' => 'fixed', 'sort' => 9]);
    BudgetLine::factory()->for($this->user)->for($insurance)->create(['amount' => 10_000, 'due_day' => 25]);

    Livewire::test('pages::month')
        ->assertSeeInOrder(['data-test="fixed-summary"', 'Fixed items', '0 of 2 done', 'data-test="fixed-due"', '1 due', 'id="month-fixed-list"', 'x-show="open"', 'data-test="fixed-item"'], false)
        ->assertSee('aria-expanded="false"', false)
        ->assertSee('aria-controls="month-fixed-list"', false)
        ->call('togglePaid', $rentLine->id)
        ->assertSee('1 of 2 done')
        ->assertDontSee('data-test="fixed-due"', false);
});

it('names the progress bar and uses the icon font for the search', function (): void {
    Livewire::test('pages::month')
        ->assertSeeInOrder(['role="progressbar"', 'aria-label="Variable spending so far"'], false)
        ->assertSeeInOrder(['data-test="month-filters"', '>search</span>', 'data-test="month-search"'], false);
});
