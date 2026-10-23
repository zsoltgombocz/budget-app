<?php

namespace App\Models;

use App\Enums\Plan;
use App\Services\Plans;
use App\Services\Watching;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use NotificationChannels\WebPush\HasPushSubscriptions;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $invited_at
 * @property Carbon|null $disabled_at
 * @property Carbon|null $last_seen_at
 * @property Plan|null $plan set by an admin (later a subscription); null: automatic
 * @property CarbonImmutable|null $plan_until
 * @property CarbonImmutable|null $plus_offer_until
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'invited_at', 'disabled_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'invited_at' => null,
        'disabled_at' => null,
        'last_seen_at' => null,
        'plan' => null,
        'plan_until' => null,
        'plus_offer_until' => null,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'invited_at' => 'datetime',
            'disabled_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'plan' => Plan::class,
            'plan_until' => 'immutable_date',
            'plus_offer_until' => 'immutable_datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The plan the user has now (see App\Services\Plans).
     */
    public function currentPlan(): Plan
    {
        return resolve(Plans::class)->current($this);
    }

    /**
     * Whether the user has MoneySight Plusz (paid, set by an admin, or the 14-day offer).
     */
    public function hasPlusz(): bool
    {
        return $this->currentPlan()->isPlus();
    }

    /**
     * @return HasMany<PlanChange, $this>
     */
    public function planChanges(): HasMany
    {
        return $this->hasMany(PlanChange::class);
    }

    /**
     * A disabled user cannot sign in, and an open session ends on the next request.
     */
    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * @return HasOne<BudgetSetting, $this>
     */
    public function budgetSetting(): HasOne
    {
        return $this->hasOne(BudgetSetting::class);
    }

    public function preferredLocale(): string
    {
        return $this->settings()->locale;
    }

    /**
     * The user's budget settings, created with defaults on first access.
     */
    public function settings(): BudgetSetting
    {
        $settings = $this->budgetSetting;

        if ($settings === null) {
            $settings = $this->budgetSetting()->create();
            $settings->refresh();
            $this->setRelation('budgetSetting', $settings);
        }

        return $settings;
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /**
     * @return HasMany<BudgetLine, $this>
     */
    public function budgetLines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }

    /**
     * @return HasMany<Period, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(Period::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<DayMark, $this>
     */
    public function dayMarks(): HasMany
    {
        return $this->hasMany(DayMark::class);
    }

    /**
     * @return HasMany<NotificationLog, $this>
     */
    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    /**
     * @return HasMany<Pocket, $this>
     */
    public function pockets(): HasMany
    {
        return $this->hasMany(Pocket::class);
    }

    /**
     * @return HasMany<PocketMovement, $this>
     */
    public function pocketMovements(): HasMany
    {
        return $this->hasMany(PocketMovement::class);
    }

    /**
     * @return HasMany<Loan, $this>
     */
    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    /**
     * @return HasMany<LoanEvent, $this>
     */
    public function loanEvents(): HasMany
    {
        return $this->hasMany(LoanEvent::class);
    }

    /**
     * Base currency changes, oldest first; the newest one is undone first when switching back.
     *
     * @return HasMany<CurrencyConversion, $this>
     */
    public function currencyConversions(): HasMany
    {
        return $this->hasMany(CurrencyConversion::class);
    }

    /**
     * @return HasMany<PeriodClose, $this>
     */
    public function periodCloses(): HasMany
    {
        return $this->hasMany(PeriodClose::class);
    }

    /**
     * @return HasMany<WatchedCategory, $this>
     */
    public function watchedCategories(): HasMany
    {
        return $this->hasMany(WatchedCategory::class);
    }

    /**
     * The watched categories shown: all with Plusz; on Alap two, the ones kept when going back
     * from Plusz, otherwise the oldest. The others stay hidden and come back with Plusz.
     *
     * @return Collection<int, WatchedCategory>
     */
    public function visibleWatchedCategories(): Collection
    {
        $query = $this->watchedCategories()->with('category')->orderByDesc('kept_on_basic')->orderBy('id');

        if (! $this->hasPlusz()) {
            $query->limit(Watching::BASE_LIMIT);
        }

        return $query->get()->sortBy('id')->values()->toBase();
    }

    /**
     * @return HasMany<ExtraIncome, $this>
     */
    public function extraIncomes(): HasMany
    {
        return $this->hasMany(ExtraIncome::class);
    }

    /**
     * @return HasMany<AcornEvent, $this>
     */
    public function acornEvents(): HasMany
    {
        return $this->hasMany(AcornEvent::class);
    }

    /**
     * @return HasMany<Achievement, $this>
     */
    public function achievements(): HasMany
    {
        return $this->hasMany(Achievement::class);
    }

    /**
     * @return HasMany<Challenge, $this>
     */
    public function challenges(): HasMany
    {
        return $this->hasMany(Challenge::class);
    }

    /**
     * @return HasMany<UserItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(UserItem::class);
    }
}
