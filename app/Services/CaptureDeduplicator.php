<?php

namespace App\Services;

use App\Enums\CaptureStatus;
use App\Enums\PeriodStatus;
use App\Enums\TransactionSource;
use App\Models\PaymentCapture;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Data\ParsedPayment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Keeps one payment from being counted twice: a re-run Shortcut, the bank and Google Wallet
 * both notifying, or the user having typed it in by hand as well.
 */
final readonly class CaptureDeduplicator
{
    /** Two captures of the same amount and merchant this close together are one payment. */
    public const int REPEAT_WINDOW_MINUTES = 5;

    public function __construct(private MerchantCategorizer $merchants) {}

    /**
     * An earlier capture of the same payment.
     */
    public function findRepeat(User $user, ParsedPayment $payment, CarbonImmutable $at): ?PaymentCapture
    {
        if (! $payment->hasAmount()) {
            return null;
        }

        return $user->paymentCaptures()
            ->where('amount', $payment->amount)
            ->where('currency', $payment->currency)
            ->where('kind', $payment->kind)
            ->where('status', '!=', CaptureStatus::Test)
            ->whereBetween('occurred_at', [$at->subMinutes(self::REPEAT_WINDOW_MINUTES), $at->addMinutes(self::REPEAT_WINDOW_MINUTES)])
            ->oldest('id')
            ->get()
            ->first(fn (PaymentCapture $capture): bool => $this->merchantsMatch($capture->merchant, $payment->merchant));
    }

    /**
     * Whether two merchant names can be the same shop. A missing name matches anything,
     * so a notification without a merchant still pairs with one that has it.
     */
    public function merchantsMatch(?string $first, ?string $second): bool
    {
        $a = $first === null ? '' : $this->merchants->key($first);
        $b = $second === null ? '' : $this->merchants->key($second);

        if ($a === '' || $b === '' || $a === $b) {
            return true;
        }

        return explode(' ', $a)[0] === explode(' ', $b)[0] || str_contains($a, $b) || str_contains($b, $a);
    }

    /**
     * A manual entry of the same amount that day which no capture is paired with yet.
     */
    public function findManualTwin(User $user, int $amount, CarbonImmutable $date): ?Transaction
    {
        return $user->transactions()
            ->where('source', TransactionSource::Manual)
            ->where('amount', $amount)
            ->whereDate('occurred_on', $date->toDateString())
            ->whereNotIn('id', PaymentCapture::query()->withoutGlobalScopes()->where('user_id', $user->id)->whereNotNull('related_transaction_id')->select('related_transaction_id'))
            ->latest('id')
            ->first();
    }

    /**
     * An automatically captured spending of the same amount on the same day, other than this
     * one: typing a payment in by hand that was already captured.
     */
    public function findAutoTwin(Transaction $transaction): ?Transaction
    {
        return Transaction::query()->withoutGlobalScopes()
            ->where('user_id', $transaction->user_id)
            ->where('source', TransactionSource::Auto)
            ->where('amount', $transaction->amount)
            ->whereDate('occurred_on', $transaction->occurred_on->toDateString())
            ->whereKeyNot($transaction->id)
            ->first();
    }

    /**
     * The spending in an open period that a refund most likely belongs to: same merchant,
     * at least the refunded amount, the exact amount first, then the newest.
     */
    public function findRefunded(User $user, int $amount, ?string $merchant, CarbonImmutable $date): ?Transaction
    {
        if ($merchant === null || $this->merchants->key($merchant) === '') {
            return null;
        }

        return $user->transactions()
            ->whereNotNull('merchant')
            ->whereNull('pocket_id')
            ->where('amount', '>=', $amount)
            ->whereDate('occurred_on', '>=', $date->subDays(90)->toDateString())
            ->whereDate('occurred_on', '<=', $date->toDateString())
            ->whereHas('period', fn (Builder $query) => $query->where('status', PeriodStatus::Open))
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Transaction $transaction): bool => $this->merchants->key((string) $transaction->merchant) !== ''
                && explode(' ', $this->merchants->key((string) $transaction->merchant))[0] === explode(' ', $this->merchants->key($merchant))[0])
            ->sortBy(fn (Transaction $transaction): int => $transaction->amount === $amount ? 0 : 1)
            ->first();
    }
}
