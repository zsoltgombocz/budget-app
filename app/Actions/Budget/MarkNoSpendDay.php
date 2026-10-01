<?php

namespace App\Actions\Budget;

use App\Models\DayMark;
use App\Models\User;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;

final readonly class MarkNoSpendDay
{
    public function __construct(private PeriodService $periods) {}

    /**
     * "I didn't spend today": silences the daily reminder for the day.
     */
    public function handle(User $user, ?CarbonImmutable $date = null): DayMark
    {
        $date ??= $this->periods->today($user->settings());

        return $user->dayMarks()->whereDate('date', $date->toDateString())->first()
            ?? $user->dayMarks()->create(['date' => $date->toDateString()]);
    }

    /**
     * Undo "I didn't spend today".
     */
    public function unmark(User $user, ?CarbonImmutable $date = null): void
    {
        $date ??= $this->periods->today($user->settings());

        $user->dayMarks()->whereDate('date', $date->toDateString())->delete();
    }

    public function isMarked(User $user, ?CarbonImmutable $date = null): bool
    {
        $date ??= $this->periods->today($user->settings());

        return $user->dayMarks()->whereDate('date', $date->toDateString())->exists();
    }
}
