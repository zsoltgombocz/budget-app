<?php

namespace App\Models;

use App\Enums\TransactionSource;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $period_id
 * @property int $category_id
 * @property int|null $account_id
 * @property int|null $pocket_id
 * @property int $amount
 * @property CarbonImmutable $occurred_on
 * @property string|null $note
 * @property string|null $merchant
 * @property int|null $orig_amount
 * @property string|null $orig_currency
 * @property TransactionSource $source
 * @property string|null $client_uuid
 * @property string|null $external_ref
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['period_id', 'category_id', 'account_id', 'pocket_id', 'amount', 'occurred_on', 'note', 'merchant', 'orig_amount', 'orig_currency', 'source', 'client_uuid', 'external_ref'])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use BelongsToUser, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'orig_amount' => 'integer',
            'occurred_on' => 'immutable_date',
            'source' => TransactionSource::class,
        ];
    }

    /**
     * @return BelongsTo<Period, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    /**
     * The pocket that paid for this spending, if any.
     *
     * @return BelongsTo<Pocket, $this>
     */
    public function pocket(): BelongsTo
    {
        return $this->belongsTo(Pocket::class);
    }

    /**
     * Deleting a pocket-paid spending puts the money back into the pocket.
     */
    protected static function booted(): void
    {
        static::deleting(function (Transaction $transaction): void {
            if ($transaction->pocket_id !== null) {
                Pocket::query()->withoutGlobalScopes()->whereKey($transaction->pocket_id)->increment('balance', $transaction->amount);
            }
        });
    }

    public function isAutoCaptured(): bool
    {
        return $this->source === TransactionSource::Auto;
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
