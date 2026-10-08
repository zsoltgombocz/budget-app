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

it('opens the entry sheet over the dashboard from /rogzites', function (): void {
    $this->get(route('entry'))->assertRedirect('/ma?rogzites=1');
    $this->get('/ma?rogzites=1')->assertOk()->assertSee('data-test="entry-sheet"', false);
});

it('offers the variable categories with what is left of their budget', function (): void {
    $options = Livewire::test('entry-sheet')->get('options');

    expect(array_column($options, 'name'))->toBe(['Fuel', 'Groceries'])
        ->and($options[0]['remaining'])->toBe(60_000);
});

it('records a spending, shows an undoable toast and refreshes the budget left', function (): void {
    $component = Livewire::test('entry-sheet')
        ->call('save', $this->fuel->id, 12_500, now()->toDateString(), null, (string) Str::uuid())
        ->assertHasNoErrors()
        ->assertDispatched('budget-updated')
        ->assertDispatched('app-toast', fn (string $name, array $params): bool => $params['undo'] === 'undo-transaction'
            && str_contains($params['title'], 'Fuel')
            && str_contains($params['subtitle'], money(47_500)));

    $transaction = Transaction::query()->sole();

    expect($transaction->amount)->toBe(12_500)
        ->and($component->get('options')[0]['remaining'])->toBe(47_500);
});

it('stores EUR amounts typed in whole euros as cents', function (): void {
    $this->user->settings()->update(['currency' => 'EUR']);

    Livewire::test('entry-sheet')->call('save', $this->fuel->id, 25, now()->toDateString(), 'Shell', (string) Str::uuid());

    expect(Transaction::query()->sole()->amount)->toBe(2_500);
});

it('does not duplicate a retried request', function (): void {
    $uuid = (string) Str::uuid();

    Livewire::test('entry-sheet')
        ->call('save', $this->fuel->id, 1_000, now()->toDateString(), null, $uuid)
        ->call('save', $this->fuel->id, 1_000, now()->toDateString(), null, $uuid);

    expect(Transaction::query()->count())->toBe(1);
});

it('undoes the last entry from the toast', function (): void {
    $component = Livewire::test('entry-sheet')->call('save', $this->fuel->id, 1_000, now()->toDateString(), null, (string) Str::uuid());

    $component->dispatch('undo-transaction', id: Transaction::query()->sole()->id);

    expect(Transaction::query()->count())->toBe(0);
});

it('rejects invalid input', function (array $arguments, string $error): void {
    Livewire::test('entry-sheet')->call('save', ...$arguments)->assertHasErrors($error);

    expect(Transaction::query()->count())->toBe(0);
})->with([
    'zero amount' => [fn (): array => [Category::query()->where('name', 'Fuel')->value('id'), 0, now()->toDateString(), null, (string) Str::uuid()], 'amount'],
    'future date' => [fn (): array => [Category::query()->where('name', 'Fuel')->value('id'), 100, now()->addDays(2)->toDateString(), null, (string) Str::uuid()], 'date'],
]);

it('rejects another user\'s category', function (): void {
    $foreign = Category::factory()->create();

    Livewire::test('entry-sheet')
        ->call('save', $foreign->id, 1_000, now()->toDateString(), null, (string) Str::uuid())
        ->assertHasErrors('category');
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
        ->call('save', $this->fuel->id, 1_000, now()->toDateString(), null, (string) Str::uuid())
        ->assertSet('noSpendMarked', false);

    expect(DayMark::query()->count())->toBe(0);
});

it('does not undo an entry once its period is closed', function (): void {
    $transaction = Transaction::factory()->for($this->user)->for($this->fuel)->create([
        'period_id' => resolve(PeriodService::class)->current($this->user)->id,
    ]);
    $transaction->period->update(['status' => PeriodStatus::Closed]);

    Livewire::test('entry-sheet')->call('undoTransaction', $transaction->id);

    expect(Transaction::query()->whereKey($transaction->id)->exists())->toBeTrue();
});
