<?php

namespace App\Models;

use App\Enums\Currency;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Database\Factories\CurrencyConversionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One change of the base currency with the MNB rates it used. The converted cells' values
 * before and after live in currency_conversion_values, so switching back is exact.
 *
 * @property int $id
 * @property int $user_id
 * @property Currency $from_currency
 * @property Currency $to_currency
 * @property string $from_rate forints per one unit of from_currency
 * @property string $to_rate forints per one unit of to_currency
 * @property CarbonImmutable $rate_date
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['from_currency', 'to_currency', 'from_rate', 'to_rate', 'rate_date'])]
class CurrencyConversion extends Model
{
    /** @use HasFactory<CurrencyConversionFactory> */
    use BelongsToUser, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'from_currency' => Currency::class,
            'to_currency' => Currency::class,
            'from_rate' => 'decimal:8',
            'to_rate' => 'decimal:8',
            'rate_date' => 'immutable_date',
        ];
    }
}
