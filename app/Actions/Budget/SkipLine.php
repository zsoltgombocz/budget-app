<?php

namespace App\Actions\Budget;

use App\Models\Period;
use App\Models\PeriodLineStatus;
use App\Models\User;
use App\Services\PeriodService;
use Illuminate\Validation\ValidationException;

final readonly class SkipLine
{
    public function __construct(private PeriodService $periods) {}

    /**
     * Leave a fixed item out of this period (it stays in the plan), or take it back. A left-out
     * item counts with 0, so its amount stays in the month-end leftover.
     */
    public function handle(User $user, Period $period, int $budgetLineId, bool $skip = true): void
    {
        if (! $period->isOpen()) {
            throw ValidationException::withMessages(['period' => __('This period is closed.')]);
        }

        $line = $user->budgetLines()->findOrFail($budgetLineId);
        $status = $period->lineStatuses()->where('budget_line_id', $line->id)->first();

        if ($status === null) {
            if (! $skip) {
                return;
            }

            $status = new PeriodLineStatus(['budget_line_id' => $line->id]);
            $status->user_id = $user->id;
        }

        $status->fill([
            'skipped_on' => $skip ? $this->periods->today($user->settings())->toDateString() : null,
            'paid_on' => $skip ? null : $status->paid_on,
        ]);
        $period->lineStatuses()->save($status);
    }
}
