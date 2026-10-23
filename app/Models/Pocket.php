<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Database\Factories\PocketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A deleted pocket is archived (soft deleted): it disappears from lists, pickers, the plan and
 * closing, but its movements and the spending it paid for stay in past periods.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $account_id
 * @property int|null $loan_id
 * @property string $name
 * @property int $balance
 * @property int|null $target_amount
 * @property CarbonImmutable|null $target_month
 * @property int|null $prepay_step
 * @property bool $is_reserve
 * @property bool $is_shared
 * @property int $sort
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable(['account_id', 'loan_id', 'name', 'balance', 'target_amount', 'target_month', 'prepay_step', 'is_reserve', 'is_shared', 'sort'])]
class Pocket extends Model
{
    /** @use HasFactory<PocketFactory> */
    use BelongsToUser, HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'target_amount' => 'integer',
            'target_month' => 'immutable_date',
            'prepay_step' => 'integer',
            'is_reserve' => 'boolean',
            'is_shared' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * @return HasMany<PocketMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(PocketMovement::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * The loan this pocket saves up prepayments for.
     *
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * @return HasMany<BudgetLine, $this>
     */
    public function budgetLines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }

    /**
     * Goals only: not the pockets that save up a loan's prepayment (those show on the loan).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function goals(Builder $query): void
    {
        $query->whereNull('loan_id');
    }

    public function hasReachedPrepayStep(): bool
    {
        return $this->prepay_step !== null && $this->prepay_step > 0 && $this->balance >= $this->prepay_step;
    }
}
