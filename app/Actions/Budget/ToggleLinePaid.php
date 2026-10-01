<?php

namespace App\Actions\Budget;

use App\Models\Period;
use App\Models\PeriodLineStatus;
use App\Models\User;
use App\Services\PeriodService;

final readonly class ToggleLinePaid
{
    public function __construct(private PeriodService $periods) {}

    /**
     * Tick a fixed item off for the period, or untick it.
     */
    public function handle(User $user, Period $period, int $budgetLineId): bool
    {
        $line = $user->budgetLines()->findOrFail($budgetLineId);
        $status = $period->lineStatuses()->where('budget_line_id', $line->id)->first();

        if ($status !== null && $status->paid_on !== null) {
            $status->update(['paid_on' => null]);

            return false;
        }

        $paidOn = $this->periods->today($user->settings())->toDateString();

        if ($status !== null) {
            $status->update(['paid_on' => $paidOn]);
        } else {
            $status = new PeriodLineStatus(['budget_line_id' => $line->id, 'paid_on' => $paidOn]);
            $status->user_id = $user->id;
            $period->lineStatuses()->save($status);
        }

        return true;
    }
}
