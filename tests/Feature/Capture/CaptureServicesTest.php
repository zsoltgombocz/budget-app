<?php

use App\Enums\CaptureReason;
use App\Enums\TransactionSource;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\PaymentCapture;
use App\Models\Transaction;
use App\Services\CaptureDeduplicator;
use App\Services\MerchantCategorizer;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->user = onboardedUser();
    $this->period = resolve(PeriodService::class)->current($this->user);
    $this->fuel = Category::query()->where('user_id', $this->user->id)->where('name', 'Fuel')->firstOrFail();
    $this->groceries = Category::query()->where('user_id', $this->user->id)->where('name', 'Groceries')->firstOrFail();
    $this->categorizer = resolve(MerchantCategorizer::class);
    $this->deduplicator = resolve(CaptureDeduplicator::class);
});

it('falls back to the first quick entry category for unknown merchants', function (): void {
    expect($this->categorizer->categoryFor($this->user, 'Ismeretlen'))->id->toBe($this->fuel->id)
        ->and($this->categorizer->categoryFor($this->user, null))->id->toBe($this->fuel->id);
});

it('ignores a default category that is no longer a variable one', function (): void {
    $rent = Category::query()->where('user_id', $this->user->id)->where('name', 'Rent')->firstOrFail();
    $this->user->settings()->update(['capture_category_id' => $rent->id]);

    expect($this->categorizer->defaultCategory($this->user->fresh()))->id->toBe($this->fuel->id);
});

it('learns a merchant and matches its other spellings', function (): void {
    $this->categorizer->learn($this->user, 'TESCO ARUHAZ BUDAPEST', $this->groceries->id);

    expect(MerchantRule::query()->where('user_id', $this->user->id)->sole()->merchant_key)->toBe('tesco aruhaz')
        ->and($this->categorizer->categoryFor($this->user, 'Tesco'))->id->toBe($this->groceries->id)
        ->and($this->categorizer->categoryFor($this->user, 'Tesco Expressz'))->id->toBe($this->groceries->id)
        ->and($this->categorizer->categoryFor($this->user, 'Lidl'))->id->toBe($this->fuel->id);
});

it('relearns when the merchant is moved again', function (): void {
    $this->categorizer->learn($this->user, 'Tesco', $this->groceries->id);
    $this->categorizer->learn($this->user, 'Tesco', $this->fuel->id);

    expect(MerchantRule::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and($this->categorizer->categoryFor($this->user, 'Tesco'))->id->toBe($this->fuel->id);
});

it('does not pair a manual entry that another capture already claimed', function (): void {
    $manual = Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['amount' => 8_400]);
    $today = CarbonImmutable::parse($manual->occurred_on->toDateString());

    expect($this->deduplicator->findManualTwin($this->user, 8_400, $today)?->id)->toBe($manual->id)
        ->and($this->deduplicator->findManualTwin($this->user, 8_500, $today))->toBeNull();

    PaymentCapture::factory()->for($this->user)->pending(CaptureReason::PossibleDuplicate)->create(['related_transaction_id' => $manual->id]);

    expect($this->deduplicator->findManualTwin($this->user, 8_400, $today))->toBeNull();
});

it('finds an automatic entry of the same amount on the same day', function (): void {
    $auto = Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['amount' => 8_400, 'source' => TransactionSource::Auto]);
    $manual = Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['amount' => 8_400]);
    $other = Transaction::factory()->for($this->user)->for($this->period)->for($this->fuel)->create(['amount' => 1_000]);

    expect($this->deduplicator->findAutoTwin($manual)?->id)->toBe($auto->id)
        ->and($this->deduplicator->findAutoTwin($other))->toBeNull();
});

it('pairs a refund with the spending of the same shop, the exact amount first', function (): void {
    $bigger = Transaction::factory()->for($this->user)->for($this->period)->for($this->groceries)->create(['amount' => 20_000, 'merchant' => 'TESCO ARUHAZ']);
    $exact = Transaction::factory()->for($this->user)->for($this->period)->for($this->groceries)->create(['amount' => 3_200, 'merchant' => 'Tesco']);
    Transaction::factory()->for($this->user)->for($this->period)->for($this->groceries)->create(['amount' => 3_200, 'merchant' => 'Lidl']);
    $today = CarbonImmutable::parse($exact->occurred_on->toDateString());

    expect($this->deduplicator->findRefunded($this->user, 3_200, 'Tesco', $today)?->id)->toBe($exact->id)
        ->and($this->deduplicator->findRefunded($this->user, 5_000, 'Tesco', $today)?->id)->toBe($bigger->id)
        ->and($this->deduplicator->findRefunded($this->user, 3_200, 'Spar', $today))->toBeNull()
        ->and($this->deduplicator->findRefunded($this->user, 3_200, null, $today))->toBeNull();
});
