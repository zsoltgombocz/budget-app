<?php

namespace App\Models;

use App\Enums\PrepayMode;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Database\Factories\LoanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $lender
 * @property int $principal_balance
 * @property int $installment
 * @property int $insurance
 * @property float|null $thm
 * @property int|null $remaining_months
 * @property PrepayMode $prepay_mode
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'lender', 'principal_balance', 'installment', 'insurance', 'thm', 'remaining_months', 'prepay_mode'])]
class Loan extends Model
{
    /** @use HasFactory<LoanFactory> */
    use BelongsToUser, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'principal_balance' => 'integer',
            'installment' => 'integer',
            'insurance' => 'integer',
            'thm' => 'float',
            'remaining_months' => 'integer',
            'prepay_mode' => PrepayMode::class,
        ];
    }

    /**
     * @return HasMany<LoanEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(LoanEvent::class);
    }

    /**
     * @return HasMany<BudgetLine, $this>
     */
    public function budgetLines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }

    /**
     * @return HasMany<Pocket, $this>
     */
    public function pockets(): HasMany
    {
        return $this->hasMany(Pocket::class);
    }

    /**
     * Monthly amount leaving the account: installment plus insurance.
     */
    public function monthlyPayment(): int
    {
        return $this->installment + $this->insurance;
    }
}
