<?php

namespace App\Models;

use App\Enums\LoanEventType;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Database\Factories\LoanEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $loan_id
 * @property LoanEventType $type
 * @property int $amount
 * @property int $principal_after
 * @property int $installment_after
 * @property int|null $remaining_months_after
 * @property CarbonImmutable $occurred_on
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['loan_id', 'type', 'amount', 'principal_after', 'installment_after', 'remaining_months_after', 'occurred_on'])]
class LoanEvent extends Model
{
    /** @use HasFactory<LoanEventFactory> */
    use BelongsToUser, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'type' => LoanEventType::class,
            'amount' => 'integer',
            'principal_after' => 'integer',
            'installment_after' => 'integer',
            'remaining_months_after' => 'integer',
            'occurred_on' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
