<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\PeriodMode;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Database\Factories\BudgetSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property PeriodMode $period_mode
 * @property int|null $payday_day
 * @property int $income
 * @property Currency $currency
 * @property string $locale
 * @property string $timezone
 * @property string $reminder_time
 * @property bool $reminder_enabled
 * @property bool $due_reminder_enabled
 * @property int $reserve_pct
 * @property int|null $reserve_fixed
 * @property int|null $surplus_pocket_id
 * @property int|null $surplus_account_id
 * @property CarbonImmutable|null $onboarded_at
 * @property CarbonImmutable|null $notifications_onboarded_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['period_mode', 'payday_day', 'income', 'currency', 'locale', 'timezone', 'reminder_time', 'reminder_enabled', 'due_reminder_enabled', 'reserve_pct', 'reserve_fixed', 'surplus_pocket_id', 'surplus_account_id', 'onboarded_at', 'notifications_onboarded_at'])]
class BudgetSetting extends Model
{
    /** @use HasFactory<BudgetSettingFactory> */
    use BelongsToUser, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'period_mode' => PeriodMode::class,
            'currency' => Currency::class,
            'payday_day' => 'integer',
            'income' => 'integer',
            'reminder_enabled' => 'boolean',
            'reserve_pct' => 'integer',
            'reserve_fixed' => 'integer',
            'onboarded_at' => 'datetime',
            'due_reminder_enabled' => 'boolean',
            'notifications_onboarded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Pocket, $this>
     */
    public function surplusPocket(): BelongsTo
    {
        return $this->belongsTo(Pocket::class, 'surplus_pocket_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function surplusAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'surplus_account_id');
    }

    public function isOnboarded(): bool
    {
        return $this->onboarded_at !== null;
    }
}
