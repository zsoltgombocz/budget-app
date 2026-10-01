<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Database\Factories\PeriodCloseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $period_id
 * @property int $planned_total
 * @property int $actual_total
 * @property int $leftover
 * @property int $to_reserve
 * @property int $to_invest
 * @property int|null $surplus_account_id
 * @property CarbonImmutable|null $surplus_transferred_at
 * @property int $from_reserve
 * @property array<string, mixed> $breakdown
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['period_id', 'planned_total', 'actual_total', 'leftover', 'to_reserve', 'to_invest', 'surplus_account_id', 'surplus_transferred_at', 'from_reserve', 'breakdown'])]
class PeriodClose extends Model
{
    /** @use HasFactory<PeriodCloseFactory> */
    use BelongsToUser, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'planned_total' => 'integer',
            'actual_total' => 'integer',
            'leftover' => 'integer',
            'to_reserve' => 'integer',
            'to_invest' => 'integer',
            'from_reserve' => 'integer',
            'breakdown' => 'array',
            'surplus_transferred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function surplusAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'surplus_account_id');
    }

    /**
     * Leftover waiting to be moved to an account outside the app.
     */
    public function awaitsTransfer(): bool
    {
        return $this->surplus_account_id !== null && $this->to_invest > 0 && $this->surplus_transferred_at === null;
    }

    /**
     * @return BelongsTo<Period, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }
}
