<?php

namespace App\Actions\Capture;

use App\Enums\CaptureReason;
use App\Enums\CaptureStatus;
use App\Models\PaymentCapture;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The user's decision on a captured payment that waits for them. Nothing here happens
 * without a tap: every money change is the one the button says.
 */
final readonly class ResolveCapture
{
    public function __construct(
        private RecordCapture $recorder,
        private PeriodService $periods,
    ) {}

    /**
     * Record it as a spending: with the amount the user typed for a foreign-currency payment,
     * anyway for a possible duplicate, and on today for a payment of a closed month.
     *
     * @param  int|null  $baseAmount  In the smallest unit of the base currency; required for a foreign-currency payment.
     */
    public function record(User $user, int $captureId, ?int $baseAmount = null): PaymentCapture
    {
        $capture = $this->pending($user, $captureId);

        $amount = $capture->reason === CaptureReason::ForeignCurrency ? $baseAmount : $capture->base_amount;

        if ($amount === null || $amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('The amount must be greater than zero.')]);
        }

        $today = $this->periods->today($user->settings());
        $date = CarbonImmutable::parse($capture->occurred_at->setTimezone($user->settings()->timezone)->toDateString());
        $period = $user->periods()->whereDate('starts_on', '<=', $date->toDateString())->whereDate('ends_on', '>=', $date->toDateString())->first();

        if ($period === null || ! $period->isOpen() || $date->greaterThan($today)) {
            $date = $today;
        }

        return DB::transaction(fn (): PaymentCapture => $this->recorder->handle($user, $capture, $amount, $date));
    }

    /**
     * Take a refund off the spending it belongs to; a spending refunded in full is removed.
     */
    public function applyRefund(User $user, int $captureId): PaymentCapture
    {
        $capture = $this->pending($user, $captureId);
        $transaction = $capture->related_transaction_id === null ? null : $user->transactions()
            ->whereKey($capture->related_transaction_id)
            ->whereRelation('period', 'status', 'open')
            ->first();

        if ($capture->reason !== CaptureReason::Refund || ! $transaction instanceof Transaction || $capture->base_amount === null) {
            throw ValidationException::withMessages(['capture' => __('There is no spending to take this refund off.')]);
        }

        return DB::transaction(function () use ($capture, $transaction): PaymentCapture {
            $left = $transaction->amount - (int) $capture->base_amount;

            if ($left > 0) {
                $transaction->update(['amount' => $left]);
            } else {
                $transaction->delete();
            }

            $capture->update(['status' => CaptureStatus::Recorded, 'resolved_at' => now()]);

            return $capture;
        });
    }

    /**
     * Leave it out: it does not become a spending.
     */
    public function dismiss(User $user, int $captureId): PaymentCapture
    {
        $capture = $this->pending($user, $captureId);
        $capture->update(['status' => CaptureStatus::Dismissed, 'resolved_at' => now()]);

        return $capture;
    }

    private function pending(User $user, int $captureId): PaymentCapture
    {
        return $user->paymentCaptures()->where('status', CaptureStatus::Pending)->findOrFail($captureId);
    }
}
