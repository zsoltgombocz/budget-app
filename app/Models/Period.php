<?php

namespace App\Models;

use App\Enums\PeriodStatus;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\PeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property int $income_planned
 * @property int|null $income_actual
 * @property PeriodStatus $status
 * @property array<string, mixed>|null $plan_snapshot
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['starts_on', 'ends_on', 'income_planned', 'income_actual', 'status', 'plan_snapshot'])]
class Period extends Model
{
    /** @use HasFactory<PeriodFactory> */
    use BelongsToUser, HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'income_planned' => 'integer',
            'income_actual' => 'integer',
            'status' => PeriodStatus::class,
            'plan_snapshot' => 'array',
        ];
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<PeriodLineStatus, $this>
     */
    public function lineStatuses(): HasMany
    {
        return $this->hasMany(PeriodLineStatus::class);
    }

    /**
     * @return HasOne<PeriodClose, $this>
     */
    public function close(): HasOne
    {
        return $this->hasOne(PeriodClose::class);
    }

    /**
     * The date whose month names the period. A period opened early by closing the previous one
     * before its end (e.g. 8 Oct – 30 Nov) is named after the month it runs into.
     */
    public function nameDate(): CarbonImmutable
    {
        return CarbonImmutable::parse(max($this->starts_on, $this->ends_on->subDays(27))->toDateString());
    }

    public function isOpen(): bool
    {
        return $this->status === PeriodStatus::Open;
    }

    public function income(): int
    {
        return $this->income_actual ?? $this->income_planned;
    }

    public function totalDays(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on) + 1;
    }

    /**
     * Days elapsed including the given day, clamped to the period length.
     */
    public function elapsedDays(CarbonInterface $today): int
    {
        if ($today->lessThan($this->starts_on)) {
            return 0;
        }

        return min($this->totalDays(), (int) $this->starts_on->diffInDays($today->startOfDay()) + 1);
    }

    /**
     * Days left including the given day.
     */
    public function remainingDays(CarbonInterface $today): int
    {
        return max(0, $this->totalDays() - $this->elapsedDays($today) + 1);
    }

    public function contains(CarbonInterface $date): bool
    {
        return $date->betweenIncluded($this->starts_on, $this->ends_on->endOfDay());
    }
}
