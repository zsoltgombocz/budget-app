<?php

namespace App\Models;

use App\Enums\CalcMode;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\BudgetLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $category_id
 * @property int $amount
 * @property int|null $amount_avg
 * @property int|null $amount_max
 * @property CalcMode $calc_mode
 * @property int|null $due_day
 * @property int|null $pocket_id
 * @property int|null $loan_id
 * @property int|null $orig_amount
 * @property string|null $orig_currency
 * @property CarbonImmutable|null $active_from
 * @property CarbonImmutable|null $active_to
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['category_id', 'amount', 'amount_avg', 'amount_max', 'calc_mode', 'due_day', 'pocket_id', 'loan_id', 'orig_amount', 'orig_currency', 'active_from', 'active_to', 'note'])]
class BudgetLine extends Model
{
    /** @use HasFactory<BudgetLineFactory> */
    use BelongsToUser, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'calc_mode' => CalcMode::class,
            'amount' => 'integer',
            'amount_avg' => 'integer',
            'amount_max' => 'integer',
            'due_day' => 'integer',
            'orig_amount' => 'integer',
            'active_from' => 'immutable_date',
            'active_to' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Pocket, $this>
     */
    public function pocket(): BelongsTo
    {
        return $this->belongsTo(Pocket::class);
    }

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * Whether the line's active window overlaps the given date range.
     */
    public function isActiveBetween(CarbonInterface $from, CarbonInterface $to): bool
    {
        if ($this->active_from !== null && $this->active_from->greaterThan($to)) {
            return false;
        }

        return $this->active_to === null || ! $this->active_to->lessThan($from);
    }
}
