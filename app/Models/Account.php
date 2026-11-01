<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\Currency;
use App\Enums\InvestmentProvider;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bank, cash or investment account. Investment accounts also carry their value in their own
 * currency, typed in by hand or read daily from the broker (credentials stay encrypted).
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property AccountType $type
 * @property Currency $currency
 * @property InvestmentProvider $provider
 * @property array<string, string>|null $credentials
 * @property int|null $value
 * @property CarbonImmutable|null $valued_on
 * @property CarbonImmutable|null $synced_at
 * @property string|null $sync_error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'type', 'currency', 'provider', 'credentials', 'value', 'valued_on', 'synced_at', 'sync_error'])]
#[Hidden(['credentials'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use BelongsToUser, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'currency' => Currency::class,
            'provider' => InvestmentProvider::class,
            'credentials' => 'encrypted:array',
            'value' => 'integer',
            'valued_on' => 'immutable_date',
            'synced_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<AccountValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(AccountValue::class);
    }
}
