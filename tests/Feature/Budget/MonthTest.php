<?php

use App\Actions\Budget\SetLineStatus;
use App\Enums\LineStatus;
use App\Enums\PeriodStatus;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\Period;
use App\Models\Pocket;
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

    $this->get(route('month'))->assertSee('Salary')->assertSee('1 item, counted at its planned amount')->assertSee("Didn't spend");
});

it('says how many days are left in the header', function (): void {
    $this->travelTo($this->period->ends_on->subDays(2)->setTime(12, 0));

    $this->get(route('month'))->assertSee('3 days left');
});

it('does not show the close-now figures for a closed period', function (): void {
    $this->travelTo($this->period->ends_on->setTime(18, 0));
    resolve(PeriodCloser::class)->close($this->user, $this->period, $this->period->income());

    Livewire::withQueryParams(['periodus' => $this->period->id])
        ->test('pages::month')
        ->assertDontSee('data-test="close-now"', false);
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

it('marks the arrow without a neighbouring month as disabled', function (): void {
    Livewire::test('pages::month')
        ->assertSeeInOrder(['aria-disabled="true"', 'data-test="previous-period"', 'aria-disabled="true"', 'data-test="next-period"'], false)
        ->assertDontSee('showPeriod(', false);

    $previous = resolve(PeriodService::class)->forDate($this->user, $this->period->starts_on->subDay());

    Livewire::test('pages::month')->assertSee('showPeriod('.$previous->id.')', false);
});

it('says so when a corrected spending no longer matches the search', function (): void {
    $transaction = Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['amount' => 12_000, 'note' => 'Shell']);
    $today = resolve(PeriodService::class)->today($this->user->settings())->toDateString();

    Livewire::test('pages::month')
        ->set('search', 'Shell')
        ->call('updateTransaction', $transaction->id, ['amount' => '12 000', 'category' => $this->fuel->id, 'date' => $today, 'note' => 'Shell station'])
        ->assertDispatched('app-toast', title: 'Spending updated.', subtitle: null)
        ->call('updateTransaction', $transaction->id, ['amount' => '12 000', 'category' => $this->fuel->id, 'date' => $today, 'note' => 'OMV'])
        ->assertDispatched('app-toast', title: 'Spending updated.', subtitle: 'It no longer matches the search, so it left the list.');
});

it('offers to undo a corrected spending with its previous values', function (): void {
    $transaction = Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['amount' => 12_000, 'note' => 'Shell']);
    $today = resolve(PeriodService::class)->today($this->user->settings())->toDateString();
    $before = $transaction->occurred_on->toDateString();

    Livewire::test('pages::month')
        ->call('updateTransaction', $transaction->id, ['amount' => '9 000', 'category' => $this->fuel->id, 'date' => $today, 'note' => 'OMV'])
        ->assertDispatched('app-toast', params: ['id' => $transaction->id, 'category' => $this->fuel->id, 'amount' => 12_000, 'date' => $before, 'note' => 'Shell'], title: 'Spending updated.', undo: 'undo-transaction-edit');
});

it('searches every month when asked, closed months read-only', function (): void {
    $previous = resolve(PeriodService::class)->forDate($this->user, $this->period->starts_on->subDay());
    Transaction::factory()->for($this->user)->for($previous)->for($this->fuel)->create(['note' => 'Old Shell', 'occurred_on' => $previous->starts_on]);
    Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['note' => 'New Shell']);
    $previous->update(['status' => PeriodStatus::Closed]);

    $month = Livewire::test('pages::month')
        ->assertDontSee('data-test="search-all"', false)
        ->set('search', 'Shell')
        ->assertSee('data-test="search-all"', false)
        ->assertSee('New Shell')->assertDontSee('Old Shell')
        ->set('searchAll', true)
        ->assertSee('New Shell')->assertSee('Old Shell');

    expect(collect($month->instance()->days)->flatMap(fn (array $day) => $day['rows'])->pluck('edit.readonly', 'note')->all())
        ->toBe(['New Shell' => false, 'Old Shell' => true]);
});

it('marks a closed month so the record button hides, and leaves skipped items out of the payday row', function (): void {
    $insurance = Category::factory()->for($this->user)->create(['name' => 'Insurance', 'type' => 'fixed']);
    $line = BudgetLine::factory()->for($this->user)->for($insurance)->create(['amount' => 10_000]);
    resolve(SetLineStatus::class)->handle($this->user, $this->period, $line->id, LineStatus::Skipped);

    Livewire::test('pages::month')
        ->assertDontSee('data-hides-record-button', false)
        ->assertSeeInOrder(['Fixed items', '1 item, counted at its planned amount', money_number(200_000)]);

    $this->travelTo($this->period->ends_on->setTime(18, 0));
    resolve(PeriodCloser::class)->close($this->user, $this->period, $this->period->income());

    Livewire::withQueryParams(['periodus' => $this->period->id])->test('pages::month')->assertSee('data-hides-record-button', false);
});

it('totals a day from the budget and says so when part of it was paid from a pocket', function (): void {
    $pocket = Pocket::factory()->for($this->user)->create(['name' => 'Reserve', 'balance' => 100_000]);
    $day = $this->period->starts_on->addDay();
    Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['amount' => 4_000, 'occurred_on' => $day]);
    Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['amount' => 40_000, 'occurred_on' => $day, 'pocket_id' => $pocket->id]);

    Livewire::test('pages::month')->assertSeeInOrder([money(4_000).' from the budget', 'paid from Reserve', money_number(40_000)]);
});
