<?php

use App\Actions\Budget\ResetBudget;
use App\Actions\Capture\IngestPayment;
use App\Enums\CaptureReason;
use App\Enums\CaptureStatus;
use App\Enums\PeriodStatus;
use App\Enums\TransactionSource;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\PaymentCapture;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PeriodService;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = onboardedUser();
    $this->actingAs($this->user);
    $this->period = resolve(PeriodService::class)->current($this->user);
    $this->fuel = Category::query()->where('name', 'Fuel')->firstOrFail();
    $this->groceries = Category::query()->where('name', 'Groceries')->firstOrFail();
    $this->ingest = fn (array $input) => resolve(IngestPayment::class)->handle($this->user, $input)->capture;
});

it('shows the payments that wait for a decision on the Today screen', function (): void {
    ($this->ingest)(['amount' => '€12.99', 'merchant' => 'Spotify']);

    $this->get(route('dashboard'))->assertOk()->assertSee('data-test="capture-inbox"', false);

    Livewire::test('capture-inbox')
        ->assertSee('Spotify')
        ->assertSee('Paid in another currency: how much was it in your currency?')
        ->assertSee('data-test="enter-amount"', false);
});

it('records a foreign-currency payment with the forint amount typed on the numpad', function (): void {
    $capture = ($this->ingest)(['amount' => '€12.99', 'merchant' => 'Spotify']);

    Livewire::test('capture-inbox')->call('record', $capture->id, 5_132)->assertDispatched('budget-updated');

    $transaction = Transaction::query()->sole();

    expect($transaction->amount)->toBe(5_132)
        ->and($transaction->orig_amount)->toBe(1_299)
        ->and($transaction->orig_currency)->toBe('EUR')
        ->and($transaction->source)->toBe(TransactionSource::Auto)
        ->and($capture->fresh()?->status)->toBe(CaptureStatus::Recorded);
});

it('needs an amount for a foreign-currency payment', function (): void {
    $capture = ($this->ingest)(['amount' => '€12.99', 'merchant' => 'Spotify']);

    Livewire::test('capture-inbox')->call('record', $capture->id)->assertHasErrors('amount');

    expect(Transaction::query()->count())->toBe(0);
});

it('settles a possible duplicate only when the user decides', function (string $action, int $spending, CaptureStatus $status): void {
    Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['amount' => 8_400]);
    $capture = ($this->ingest)(['amount' => '8 400 Ft', 'merchant' => 'MOL']);

    expect($capture->reason)->toBe(CaptureReason::PossibleDuplicate)
        ->and(Transaction::query()->count())->toBe(1);

    Livewire::test('capture-inbox')->call($action, $capture->id);

    expect(Transaction::query()->count())->toBe($spending)
        ->and($capture->fresh()?->status)->toBe($status);
})->with([
    'same payment' => ['dismiss', 1, CaptureStatus::Dismissed],
    'record it too' => ['record', 2, CaptureStatus::Recorded],
]);

it('takes a partial refund off the spending and removes a fully refunded one', function (int $refund, ?int $left): void {
    ($this->ingest)(['amount' => '8 400 Ft', 'merchant' => 'Tesco']);
    $spending = Transaction::query()->sole();
    $capture = ($this->ingest)(['text' => 'Refund of '.$refund.' HUF from Tesco']);

    Livewire::test('capture-inbox')->call('applyRefund', $capture->id)->assertDispatched('budget-updated');

    expect($spending->fresh()?->amount)->toBe($left)
        ->and($capture->fresh()?->status)->toBe(CaptureStatus::Recorded);
})->with([
    'partial' => [3_200, 5_200],
    'full' => [8_400, null],
]);

it('records a payment of a closed month for today', function (): void {
    $this->period->update(['status' => PeriodStatus::Closed]);
    $capture = ($this->ingest)(['amount' => '8 400 Ft', 'merchant' => 'Tesco']);
    $this->period->update(['status' => PeriodStatus::Open]);

    Livewire::test('capture-inbox')->call('record', $capture->id);

    expect(Transaction::query()->sole()->occurred_on->toDateString())->toBe(resolve(PeriodService::class)->today($this->user->settings())->toDateString());
});

it('cannot resolve someone else’s capture', function (): void {
    $foreign = PaymentCapture::factory()->for(User::factory())->pending(CaptureReason::PossibleDuplicate)->create();

    Livewire::test('capture-inbox')->call('dismiss', $foreign->id)->assertNotFound();

    expect($foreign->fresh()?->status)->toBe(CaptureStatus::Pending);
});

it('lists today’s automatic spending and moves a shop to another category for good', function (): void {
    ($this->ingest)(['amount' => '8 400 Ft', 'merchant' => 'Tesco']);
    $transaction = Transaction::query()->sole();

    expect($transaction->category_id)->toBe($this->fuel->id);

    Livewire::test('capture-inbox')
        ->assertSee('Captured today')
        ->assertSee('Tesco')
        ->call('recategorize', $transaction->id, $this->groceries->id)
        ->assertDispatched('app-toast', fn (string $name, array $params): bool => str_contains($params['title'], 'Tesco goes to Groceries'));

    expect($transaction->fresh()?->category_id)->toBe($this->groceries->id)
        ->and(MerchantRule::query()->sole()->category_id)->toBe($this->groceries->id);

    $this->travel(10)->minutes();
    ($this->ingest)(['text' => 'Kártyás fizetés: -2.100,00 HUF, TESCO ARUHAZ BUDAPEST']);

    expect(Transaction::query()->latest('id')->first()?->category_id)->toBe($this->groceries->id);
});

it('deletes an automatic spending with one tap', function (): void {
    ($this->ingest)(['amount' => '8 400 Ft', 'merchant' => 'Tesco']);

    Livewire::test('capture-inbox')->call('delete', Transaction::query()->sole()->id)->assertDispatched('budget-updated');

    expect(Transaction::query()->count())->toBe(0);
});

it('refreshes the screen when a new payment arrives while it is open', function (): void {
    $component = Livewire::test('capture-inbox')->call('checkForNew')->assertNotDispatched('budget-updated');

    ($this->ingest)(['amount' => '8 400 Ft', 'merchant' => 'Tesco']);

    $component->call('checkForNew')->assertDispatched('budget-updated')->assertSee('Tesco');
});

it('marks automatic spending on the month page and lets it be recategorised there', function (): void {
    ($this->ingest)(['amount' => '8 400 Ft', 'merchant' => 'Tesco']);
    $transaction = Transaction::query()->sole();

    Livewire::test('pages::month')
        ->assertSee('data-test="auto-captured"', false)
        ->call('recategorize', $transaction->id, $this->groceries->id)
        ->assertDispatched('budget-updated');

    expect($transaction->fresh()?->category_id)->toBe($this->groceries->id);
});

it('warns when a typed-in spending was already captured automatically', function (): void {
    ($this->ingest)(['amount' => '8 400 Ft', 'merchant' => 'Tesco']);

    Livewire::test('entry-sheet')
        ->call('save', $this->fuel->id, 8_400, now()->toDateString(), null, (string) Str::uuid())
        ->assertDispatched('app-toast', fn (string $name, array $params): bool => $params['subtitle'] === 'Already captured automatically today?');
});

it('forgets captures when the budget is reset', function (): void {
    ($this->ingest)(['amount' => '8 400 Ft', 'merchant' => 'Tesco']);
    MerchantRule::factory()->for($this->user)->for($this->fuel)->create();

    resolve(ResetBudget::class)->handle($this->user);

    expect(PaymentCapture::query()->count())->toBe(0)
        ->and(MerchantRule::query()->count())->toBe(0);
});

it('prunes raw notification texts after a week and old settled captures', function (): void {
    $fresh = PaymentCapture::factory()->for($this->user)->create(['raw_text' => 'fresh']);
    $week = PaymentCapture::factory()->for($this->user)->create(['raw_text' => 'old', 'created_at' => now()->subDays(8)]);
    $old = PaymentCapture::factory()->for($this->user)->create(['created_at' => now()->subDays(91)]);
    $oldPending = PaymentCapture::factory()->for($this->user)->pending(CaptureReason::Refund)->create(['created_at' => now()->subDays(91)]);

    $this->artisan('captures:prune')->assertSuccessful();

    expect($fresh->fresh()?->raw_text)->toBe('fresh')
        ->and($week->fresh()?->raw_text)->toBeNull()
        ->and($old->fresh())->toBeNull()
        ->and($oldPending->fresh())->not->toBeNull();
});
