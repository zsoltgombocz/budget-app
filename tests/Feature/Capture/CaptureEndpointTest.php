<?php

use App\Enums\CaptureReason;
use App\Enums\CaptureStatus;
use App\Enums\PeriodStatus;
use App\Enums\TransactionSource;
use App\Models\CaptureToken;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\PaymentCapture;
use App\Models\Transaction;
use App\Models\User;
use App\Services\OverviewService;
use App\Services\PeriodService;
use Illuminate\Testing\TestResponse;

const CAPTURE_KEY = 'msc_testkey0123456789012345678901234567890';

beforeEach(function (): void {
    $this->user = onboardedUser();
    CaptureToken::factory()->for($this->user)->plain(CAPTURE_KEY)->create();
});

/**
 * @param  array<string, mixed>  $payload
 */
function sendCapture(array $payload, string $key = CAPTURE_KEY): TestResponse
{
    return test()->withToken($key)->postJson(route('api.captures.store'), $payload);
}

it('rejects requests without a valid key', function (?string $key): void {
    $request = $key === null ? $this->postJson(route('api.captures.store'), ['amount' => '8 400 Ft']) : sendCapture(['amount' => '8 400 Ft'], $key);

    $request->assertUnauthorized()->assertJson(['status' => 'unauthorized']);

    expect(Transaction::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'no key' => [null],
    'unknown key' => ['msc_unknown'],
    'not a capture key' => ['some-session-token'],
]);

it('stops working once the key is deleted', function (): void {
    $this->user->captureTokens()->delete();

    sendCapture(['amount' => '8 400 Ft'])->assertUnauthorized();
});

it('refuses a disabled account', function (): void {
    $this->user->update(['disabled_at' => now()]);

    sendCapture(['amount' => '8 400 Ft'])->assertForbidden();
});

it('waits until the user finished onboarding', function (): void {
    $this->user->settings()->update(['onboarded_at' => null]);

    sendCapture(['amount' => '8 400 Ft'])->assertConflict()->assertJson(['status' => 'not_ready']);
});

it('records an Apple Pay payment as an automatic spending that lowers the leftover at once', function (): void {
    $overview = fn () => resolve(OverviewService::class)->forUser($this->user->fresh());
    $before = $overview()->forecast;

    sendCapture(['amount' => '8 400 Ft', 'merchant' => 'Tesco', 'platform' => 'ios'])
        ->assertCreated()
        ->assertJson(['status' => 'recorded'])
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Tesco') && str_contains($message, 'Fuel'));

    $transaction = Transaction::query()->withoutGlobalScopes()->sole();
    $after = $overview()->forecast;

    expect($transaction->source)->toBe(TransactionSource::Auto)
        ->and($transaction->amount)->toBe(8_400)
        ->and($transaction->merchant)->toBe('Tesco')
        ->and($transaction->user_id)->toBe($this->user->id)
        ->and($transaction->category->name)->toBe('Fuel')
        ->and($after->variableSpent)->toBe($before->variableSpent + 8_400)
        ->and($after->dailyAllowance)->toBeLessThan($before->dailyAllowance)
        ->and(PaymentCapture::query()->withoutGlobalScopes()->sole()->transaction_id)->toBe($transaction->id);
});

it('puts a known merchant into its remembered category', function (): void {
    $groceries = Category::query()->withoutGlobalScopes()->where('name', 'Groceries')->firstOrFail();
    MerchantRule::factory()->for($this->user)->create(['merchant_key' => 'tesco', 'category_id' => $groceries->id]);

    sendCapture(['text' => 'Kártyás fizetés: -8.400,00 HUF, TESCO ARUHAZ BUDAPEST', 'title' => 'George', 'platform' => 'android'])->assertCreated();

    expect(Transaction::query()->withoutGlobalScopes()->sole()->category_id)->toBe($groceries->id);
});

it('uses the default capture category from the settings for unknown merchants', function (): void {
    $groceries = Category::query()->withoutGlobalScopes()->where('name', 'Groceries')->firstOrFail();
    $this->user->settings()->update(['capture_category_id' => $groceries->id]);

    sendCapture(['amount' => '2 990 Ft', 'merchant' => 'Ismeretlen Bolt'])->assertCreated();

    expect(Transaction::query()->withoutGlobalScopes()->sole()->category_id)->toBe($groceries->id);
});

it('stores a payment only once when the same request is sent again', function (): void {
    sendCapture(['amount' => '8 400 Ft', 'merchant' => 'Tesco', 'id' => 'wallet-123'])->assertCreated();
    sendCapture(['amount' => '8 400 Ft', 'merchant' => 'Tesco', 'id' => 'wallet-123'])->assertOk()->assertJson(['status' => 'duplicate']);

    expect(Transaction::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(PaymentCapture::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('treats a re-run of the Shortcut within minutes as the same payment', function (): void {
    sendCapture(['amount' => '8 400 Ft', 'merchant' => 'Tesco']);
    $this->travel(2)->minutes();
    sendCapture(['amount' => '8 400 Ft', 'merchant' => 'TESCO'])->assertJson(['status' => 'duplicate']);

    expect(Transaction::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('counts a bank and a Google Wallet notification of one payment once', function (): void {
    sendCapture(['text' => '8 400 Ft · Visa •••• 1234', 'title' => 'Tesco', 'platform' => 'android'])->assertCreated();
    sendCapture(['text' => 'OTPdirekt - Vásárlás: -8 400 HUF; TESCO ARUHAZ BUDAPEST; Egyenleg: +123 456 HUF', 'title' => 'OTP Bank', 'platform' => 'android'])
        ->assertJson(['status' => 'duplicate']);

    expect(Transaction::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('records the same amount again once the repeat window is over', function (): void {
    sendCapture(['amount' => '650 Ft', 'merchant' => 'Kávézó']);
    $this->travel(10)->minutes();
    sendCapture(['amount' => '650 Ft', 'merchant' => 'Kávézó'])->assertCreated();

    expect(Transaction::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('asks before counting a payment the user already typed in by hand', function (): void {
    $fuel = Category::query()->withoutGlobalScopes()->where('name', 'Fuel')->firstOrFail();
    $period = resolve(PeriodService::class)->current($this->user);
    $manual = Transaction::factory()->for($this->user)->for($period)->for($fuel)->create(['amount' => 8_400]);

    sendCapture(['amount' => '8 400 Ft', 'merchant' => 'MOL'])
        ->assertOk()
        ->assertJson(['status' => 'pending', 'reason' => 'possible_duplicate']);

    $capture = PaymentCapture::query()->withoutGlobalScopes()->sole();

    expect(Transaction::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and($capture->related_transaction_id)->toBe($manual->id);
});

it('keeps a foreign-currency payment waiting for its forint amount', function (): void {
    sendCapture(['amount' => '€12.99', 'merchant' => 'Spotify'])
        ->assertOk()
        ->assertJson(['status' => 'pending', 'reason' => 'foreign_currency']);

    $capture = PaymentCapture::query()->withoutGlobalScopes()->sole();

    expect($capture->amount)->toBe(1_299)
        ->and($capture->currency)->toBe('EUR')
        ->and(Transaction::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('records a foreign-currency payment when the bank also tells the forint amount', function (): void {
    sendCapture(['text' => 'OTPdirekt - Vásárlás: -12,99 EUR (-5 132 HUF); SPOTIFY AB STOCKHOLM; Egyenleg: +118 324 HUF'])->assertCreated();

    $transaction = Transaction::query()->withoutGlobalScopes()->sole();

    expect($transaction->amount)->toBe(5_132)
        ->and($transaction->orig_amount)->toBe(1_299)
        ->and($transaction->orig_currency)->toBe('EUR');
});

it('keeps a refund waiting, paired with the spending it belongs to', function (): void {
    sendCapture(['amount' => '8 400 Ft', 'merchant' => 'Tesco']);
    $spending = Transaction::query()->withoutGlobalScopes()->sole();

    sendCapture(['text' => 'OTPdirekt - Jóváírás (visszatérítés): +3 200 HUF; TESCO ARUHAZ BUDAPEST'])
        ->assertJson(['status' => 'pending', 'reason' => 'refund']);

    expect(PaymentCapture::query()->withoutGlobalScopes()->where('reason', CaptureReason::Refund)->sole()->related_transaction_id)->toBe($spending->id)
        ->and($spending->fresh()?->amount)->toBe(8_400);
});

it('skips what is not a card payment', function (string $text): void {
    sendCapture(['text' => $text])->assertOk()->assertJson(['status' => 'ignored']);

    expect(Transaction::query()->withoutGlobalScopes()->count())->toBe(0);
})->with([
    'salary' => ['OTPdirekt - Átutalás jóváírás: +609 000 HUF; Közlemény: munkabér'],
    'outgoing transfer' => ['K&H mobilbank: átutalás terhelés 150 000 Ft, Kedvezményezett: Kovács Anna'],
    'cash withdrawal' => ['K&H mobilbank: ATM készpénzfelvétel 20 000 Ft'],
    'declined' => ['Elutasított kártyás fizetés: 8.400,00 HUF, TESCO'],
    'no amount' => ['Új ajánlat vár a számládhoz!'],
]);

it('holds a payment whose day belongs to a closed month', function (): void {
    $period = resolve(PeriodService::class)->current($this->user);
    $period->update(['status' => PeriodStatus::Closed]);

    sendCapture(['amount' => '8 400 Ft', 'merchant' => 'Tesco'])->assertJson(['status' => 'pending', 'reason' => 'period_closed']);

    expect(Transaction::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('answers a connection test without recording anything', function (array $payload): void {
    sendCapture($payload)->assertOk()->assertJson(['status' => 'test']);

    expect(PaymentCapture::query()->withoutGlobalScopes()->sole()->status)->toBe(CaptureStatus::Test)
        ->and(Transaction::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($this->user->captureTokens()->sole()->last_used_at)->not->toBeNull();
})->with([
    'explicit test' => [['test' => true, 'amount' => '100 Ft']],
    'manual Shortcut run without Wallet data' => [['amount' => '', 'merchant' => '']],
]);

it('accepts the amount as a JSON number', function (): void {
    sendCapture(['amount' => 8400, 'merchant' => 'Tesco'])->assertCreated();

    expect(Transaction::query()->withoutGlobalScopes()->sole()->amount)->toBe(8_400);
});

it('validates the payload', function (): void {
    sendCapture(['text' => str_repeat('a', 2001)])->assertUnprocessable()->assertJsonValidationErrors('text');
});

it('answers in the language of the user', function (): void {
    $this->user->settings()->update(['locale' => 'hu']);

    sendCapture(['test' => true])->assertJsonPath('message', 'A kapcsolat működik. A MoneySight készen áll.');
});

it('only touches the key owner’s data', function (): void {
    $other = User::factory()->create();

    sendCapture(['amount' => '8 400 Ft', 'merchant' => 'Tesco'])->assertCreated();

    expect($other->transactions()->count())->toBe(0)
        ->and($this->user->transactions()->count())->toBe(1);
});

it('rate limits a key', function (): void {
    foreach (range(1, 20) as $attempt) {
        sendCapture(['test' => true])->assertOk();
    }

    sendCapture(['test' => true])->assertTooManyRequests();
});
