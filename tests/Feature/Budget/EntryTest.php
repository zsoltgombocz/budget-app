<?php

use App\Enums\PeriodStatus;
use App\Models\Category;
use App\Models\DayMark;
use App\Models\Transaction;
use App\Services\PeriodService;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = onboardedUser();
    $this->actingAs($this->user);
    $this->fuel = Category::query()->where('name', 'Fuel')->firstOrFail();
});

/**
 * The entry sheet's form as the Alpine side sends it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function entryForm(array $overrides = []): array
{
    return [
        'category' => Category::query()->where('name', 'Fuel')->value('id'),
        'amount' => '1000',
        // The user's own today (Europe/Budapest), as the sheet sends it; around midnight it differs from UTC.
        'date' => resolve(PeriodService::class)->today(test()->user->settings())->toDateString(),
        'note' => null,
        'clientUuid' => (string) Str::uuid(),
        ...$overrides,
    ];
}

it('opens the entry sheet over the dashboard from /rogzites', function (): void {
    $this->get(route('entry'))->assertRedirect('/ma?rogzites=1');
    $this->get('/ma?rogzites=1')->assertOk()->assertSee('data-test="entry-sheet"', false);
});

it('offers the spending categories, the most used first', function (): void {
    expect(array_column(Livewire::test('entry-sheet')->get('options'), 'name'))->toBe(['Fuel', 'Groceries']);

    $groceries = Category::query()->where('name', 'Groceries')->firstOrFail();
    Transaction::factory()->for($this->user)->for(resolve(PeriodService::class)->current($this->user))->for($groceries)->create(['occurred_on' => now()->toDateString()]);

    expect(array_column(Livewire::test('entry-sheet')->get('options'), 'name'))->toBe(['Groceries', 'Fuel']);
});

it('records a spending, shows an undoable toast with what is left of today', function (): void {
    $component = Livewire::test('entry-sheet');
    $allowance = $component->get('month')['allowance'];

    $result = $component->call('save', entryForm(['amount' => '1000']))
        ->assertDispatched('budget-updated')
        ->assertDispatched('app-toast', fn (string $name, array $params): bool => $params['undo'] === 'undo-transaction'
            && str_contains($params['title'], 'Fuel')
            && $params['subtitle'] === 'Daily allowance: '.money($allowance - 1_000).' left')
        ->effects['returns'][0];

    expect($result)->toBe(['ok' => true, 'errors' => []])
        ->and(Transaction::query()->sole()->amount)->toBe(1_000)
        ->and($component->get('month')['todayLeft'])->toBe($allowance - 1_000);
});

it('says by how much a spending goes over today and what tomorrow gets', function (): void {
    $component = Livewire::test('entry-sheet');
    $allowance = $component->get('month')['allowance'];

    $component->call('save', entryForm(['amount' => (string) ($allowance + 2_000)]))
        ->assertDispatched('app-toast', fn (string $name, array $params): bool => str_starts_with((string) $params['subtitle'], money(2_000).' over today.'));
});

it('records extra income, counted in the month at once, and undoes it', function (): void {
    $component = Livewire::test('entry-sheet');
    $remaining = $component->get('month')['remaining'];

    $component->call('saveIncome', ['amount' => '15000', 'note' => 'Gift', 'clientUuid' => (string) Str::uuid()])
        ->assertReturned(['ok' => true, 'errors' => []])
        ->assertDispatched('app-toast', fn (string $name, array $params): bool => $params['undo'] === 'undo-extra-income');

    expect($component->get('month')['remaining'])->toBe($remaining + 15_000)
        ->and($this->user->extraIncomes()->sole()->note)->toBe('Gift');

    $component->dispatch('undo-extra-income', id: $this->user->extraIncomes()->sole()->id);

    expect($this->user->extraIncomes()->count())->toBe(0);
});

it('refuses extra income without an amount', function (): void {
    Livewire::test('entry-sheet')
        ->call('saveIncome', ['amount' => '', 'clientUuid' => (string) Str::uuid()])
        ->assertReturned(['ok' => false, 'errors' => ['amount' => 'Enter a valid amount.']]);
});

it('offers a chip for an amount recorded three times in 30 days', function (): void {
    $period = resolve(PeriodService::class)->current($this->user);
    Transaction::factory()->for($this->user)->for($period)->for($this->fuel)->count(3)->create(['amount' => 8_900, 'occurred_on' => now()->toDateString()]);

    expect(Livewire::test('entry-sheet')->get('frequent'))->toBe([['category_id' => $this->fuel->id, 'amount' => 8_900, 'count' => 3]]);
});

it('offers the 000 key in forint and a decimal key in a currency with cents', function (): void {
    Livewire::test('entry-sheet')->assertSeeHtml('data-key="000"')->assertDontSeeHtml('data-key=","');

    $this->user->settings()->update(['currency' => 'EUR']);

    Livewire::test('entry-sheet')->assertSeeHtml('data-key=","')->assertDontSeeHtml('data-key="000"');
});

it('stores the exact amount in minor units', function (string $currency, string $typed, int $stored): void {
    $this->user->settings()->update(['currency' => $currency]);

    Livewire::test('entry-sheet')->call('save', entryForm(['amount' => $typed, 'note' => 'Shell']));

    expect(Transaction::query()->sole()->amount)->toBe($stored);
})->with([
    'forint' => ['HUF', '12500', 12_500],
    'whole euros' => ['EUR', '25', 2_500],
    'euros with cents' => ['EUR', '12,34', 1_234],
    'one cent' => ['EUR', '0,01', 1],
    'one decimal place' => ['EUR', '0,5', 50],
]);

it('does not duplicate a retried request', function (): void {
    $form = entryForm();

    Livewire::test('entry-sheet')->call('save', $form)->call('save', $form);

    expect(Transaction::query()->count())->toBe(1);
});

it('undoes the last entry from the toast', function (): void {
    $component = Livewire::test('entry-sheet')->call('save', entryForm());

    $component->dispatch('undo-transaction', id: Transaction::query()->sole()->id);

    expect(Transaction::query()->count())->toBe(0);
});

it('rejects invalid input with the reason and saves nothing', function (array $overrides, string $field, ?string $message): void {
    $result = Livewire::test('entry-sheet')->call('save', entryForm($overrides))->effects['returns'][0];

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'])->toHaveKey($field)
        ->and(Transaction::query()->count())->toBe(0);

    if ($message !== null) {
        expect($result['errors'][$field])->toBe($message);
    }
})->with([
    'zero amount' => [['amount' => '0'], 'amount', 'The amount must be greater than zero.'],
    'not a number' => [['amount' => '12a'], 'amount', 'Enter a valid amount.'],
    'cents in forint' => [['amount' => '12,5'], 'amount', 'Enter a valid amount.'],
    'future date' => [fn (): array => ['date' => now()->addDays(2)->toDateString()], 'date', 'You cannot record spending on a future day.'],
    'another user\'s category' => [fn (): array => ['category' => Category::factory()->create()->id], 'category', 'Choose a category.'],
]);

it('rejects more cents than the currency has', function (): void {
    $this->user->settings()->update(['currency' => 'EUR']);

    $result = Livewire::test('entry-sheet')->call('save', entryForm(['amount' => '1,234']))->effects['returns'][0];

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'])->toHaveKey('amount')
        ->and(Transaction::query()->count())->toBe(0);
});

it('toggles "I didn\'t spend today" and can undo it', function (): void {
    $component = Livewire::test('entry-sheet')
        ->call('toggleNoSpend')
        ->assertSet('noSpendMarked', true)
        ->assertDispatched('app-toast', fn (string $name, array $params): bool => $params['undo'] === 'undo-no-spend');

    expect(DayMark::query()->count())->toBe(1);

    $component->call('toggleNoSpend')->assertSet('noSpendMarked', false);

    expect(DayMark::query()->count())->toBe(0);
});

it('clears the no-spend mark when spending is recorded that day', function (): void {
    Livewire::test('entry-sheet')
        ->call('toggleNoSpend')
        ->call('save', entryForm(['date' => resolve(PeriodService::class)->today($this->user->settings())->toDateString()]))
        ->assertSet('noSpendMarked', false);

    expect(DayMark::query()->count())->toBe(0);
});

it('reloads today on refresh, so a sheet kept open past midnight moves to the new day', function (): void {
    $this->travelTo(now($this->user->settings()->timezone)->setTime(23, 50));
    $component = Livewire::test('entry-sheet');
    $yesterday = $component->get('today');

    $this->travel(20)->minutes();
    $component->call('refresh');

    expect($component->get('today'))->not->toBe($yesterday)
        ->and($component->get('today'))->toBe(resolve(PeriodService::class)->today($this->user->settings())->toDateString());
});

it('does not undo an entry once its period is closed', function (): void {
    $transaction = Transaction::factory()->for($this->user)->for($this->fuel)->create([
        'period_id' => resolve(PeriodService::class)->current($this->user)->id,
    ]);
    $transaction->period->update(['status' => PeriodStatus::Closed]);

    Livewire::test('entry-sheet')->call('undoTransaction', $transaction->id);

    expect(Transaction::query()->whereKey($transaction->id)->exists())->toBeTrue();
});
