<?php

namespace App\Models;

use App\Enums\CaptureReason;
use App\Enums\CaptureStatus;
use App\Enums\Currency;
use App\Enums\PaymentKind;
use App\Models\Concerns\BelongsToUser;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentCaptureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use NumberFormatter;

/**
 * A payment the phone sent in, and what became of it.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $transaction_id
 * @property int|null $related_transaction_id
 * @property string|null $external_id
 * @property CaptureStatus $status
 * @property CaptureReason|null $reason
 * @property PaymentKind $kind
 * @property int|null $amount Amount paid, in the smallest unit of `currency`.
 * @property string|null $currency
 * @property int|null $base_amount Amount in the user's base currency, when known.
 * @property string|null $merchant
 * @property string|null $platform
 * @property CarbonImmutable $occurred_at
 * @property string|null $raw_text
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['transaction_id', 'related_transaction_id', 'external_id', 'status', 'reason', 'kind', 'amount', 'currency', 'base_amount', 'merchant', 'platform', 'occurred_at', 'raw_text', 'resolved_at'])]
class PaymentCapture extends Model
{
    /** @use HasFactory<PaymentCaptureFactory> */
    use BelongsToUser, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'status' => CaptureStatus::class,
            'reason' => CaptureReason::class,
            'kind' => PaymentKind::class,
            'amount' => 'integer',
            'base_amount' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * @return BelongsTo<Transaction, $this>
     */
    public function relatedTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'related_transaction_id');
    }

    /**
     * The amount as it was paid, e.g. "12,99 €" or "8 400 Ft".
     */
    public function formattedAmount(): string
    {
        if ($this->amount === null || $this->currency === null) {
            return '–';
        }

        $currency = Currency::tryFrom($this->currency);

        if ($currency instanceof Currency) {
            return Money::of($this->amount, $currency)->format();
        }

        $formatter = new NumberFormatter(app()->getLocale(), NumberFormatter::CURRENCY);

        return str_replace("\u{202F}", "\u{00A0}", (string) $formatter->formatCurrency($this->amount / 100, $this->currency));
    }
}
