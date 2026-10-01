<?php

use App\Models\Category;
use App\Models\DayMark;
use App\Models\Transaction;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = onboardedUser();
    $this->actingAs($this->user);
    $this->fuel = Category::query()->where('name', 'Fuel')->firstOrFail();
});

it('renders the quick entry grid with variable categories only', function (): void {
    $this->get(route('entry'))
        ->assertOk()
        ->assertSee('Fuel')
        ->assertSee('Groceries')
        ->assertDontSee('Rent');
});

it('records a spending in one call after picking a category', function (): void {
    Livewire::test('pages::entry')
        ->call('save', $this->fuel->id, 12_500, now()->toDateString(), null, (string) Str::uuid())
        ->assertHasNoErrors()
        ->assertNotSet('lastTransactionId', null);

    $transaction = Transaction::query()->sole();

    expect($transaction->amount)->toBe(12_500)
        ->and($transaction->category_id)->toBe($this->fuel->id)
        ->and($transaction->period->contains(now()))->toBeTrue();
});

it('stores EUR amounts typed in whole euros as cents', function (): void {
    $this->user->settings()->update(['currency' => 'EUR']);

    Livewire::test('pages::entry')
        ->call('save', $this->fuel->id, 25, now()->toDateString(), 'Shell', (string) Str::uuid());

    expect(Transaction::query()->sole()->amount)->toBe(2_500);
});

it('does not duplicate a retried request', function (): void {
    $uuid = (string) Str::uuid();

    Livewire::test('pages::entry')
        ->call('save', $this->fuel->id, 1_000, now()->toDateString(), null, $uuid)
        ->call('save', $this->fuel->id, 1_000, now()->toDateString(), null, $uuid);

    expect(Transaction::query()->count())->toBe(1);
});

it('undoes the last entry', function (): void {
    Livewire::test('pages::entry')
        ->call('save', $this->fuel->id, 1_000, now()->toDateString(), null, (string) Str::uuid())
        ->call('undo')
        ->assertSet('lastTransactionId', null);

    expect(Transaction::query()->count())->toBe(0);
});

it('rejects invalid input', function (array $arguments, string $error): void {
    Livewire::test('pages::entry')
        ->call('save', ...$arguments)
        ->assertHasErrors($error);

    expect(Transaction::query()->count())->toBe(0);
})->with([
    'zero amount' => [fn (): array => [Category::query()->where('name', 'Fuel')->value('id'), 0, now()->toDateString(), null, (string) Str::uuid()], 'amount'],
    'future date' => [fn (): array => [Category::query()->where('name', 'Fuel')->value('id'), 100, now()->addDays(2)->toDateString(), null, (string) Str::uuid()], 'date'],
]);

it('rejects another user\'s category', function (): void {
    $foreign = Category::factory()->create();

    Livewire::test('pages::entry')
        ->call('save', $foreign->id, 1_000, now()->toDateString(), null, (string) Str::uuid())
        ->assertHasErrors('category');
});

it('marks a no-spend day once', function (): void {
    Livewire::test('pages::entry')->call('markNoSpend')->assertRedirect(route('dashboard'));
    Livewire::test('pages::entry')->call('markNoSpend');

    expect(DayMark::query()->count())->toBe(1);
});
