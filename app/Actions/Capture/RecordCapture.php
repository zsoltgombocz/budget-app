<?php

namespace App\Actions\Capture;

use App\Actions\Budget\RecordTransaction;
use App\Enums\CaptureReason;
use App\Enums\CaptureStatus;
use App\Enums\TransactionSource;
use App\Models\Category;
use App\Models\PaymentCapture;
use App\Models\User;
use App\Services\MerchantCategorizer;
use Carbon\CarbonImmutable;

/**
 * Turns a captured payment into a spending: the merchant's remembered category (or the
 * default one), marked as captured automatically. From then on it counts in the expected
 * leftover and the daily allowance like any other spending.
 */
final readonly class RecordCapture
{
    public function __construct(
        private RecordTransaction $transactions,
        private MerchantCategorizer $categorizer,
    ) {}

    public function handle(User $user, PaymentCapture $capture, int $baseAmount, CarbonImmutable $date): PaymentCapture
    {
        $category = $this->categorizer->categoryFor($user, $capture->merchant);

        if (! $category instanceof Category) {
            $capture->update(['status' => CaptureStatus::Pending, 'reason' => CaptureReason::NoCategory]);

            return $capture;
        }

        $foreign = $capture->currency !== null && $capture->currency !== $user->settings()->currency->value;

        $transaction = $this->transactions->handle(
            $user,
            $category->id,
            $baseAmount,
            $date,
            source: TransactionSource::Auto,
            merchant: $capture->merchant,
            origAmount: $foreign ? $capture->amount : null,
            origCurrency: $foreign ? $capture->currency : null,
        );

        $capture->update([
            'status' => CaptureStatus::Recorded,
            'transaction_id' => $transaction->id,
            'base_amount' => $baseAmount,
            'resolved_at' => $capture->status === CaptureStatus::Pending ? now() : null,
        ]);

        return $capture;
    }
}
