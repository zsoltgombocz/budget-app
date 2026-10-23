<?php

namespace App\Actions\Month;

use App\Models\Period;
use App\Models\PeriodLineStatus;
use App\Models\User;
use App\Services\PeriodService;

/**
 * "Ebben a hónapban: nem lesz / más összeg" on a fixed line. Both are for this month only and can be
 * set back; the month's money changes by exactly the difference.
 */
final readonly class SetLineThisMonth
{
    public function __construct(private PeriodService $periods) {}

    public function skip(User $user, Period $period, int $budgetLineId, bool $skipped = true): void
    {
        $line = $user->budgetLines()->findOrFail($budgetLineId);
        $status = $this->status($user, $period, $line->id);

        $status->fill(['skipped_on' => $skipped ? $this->periods->today($user->settings())->toDateString() : null])->save();
    }

    /**
     * @param  int|null  $amount  null (or the line's own amount) counts the line's own amount again
     */
    public function amount(User $user, Period $period, int $budgetLineId, ?int $amount): void
    {
        $line = $user->budgetLines()->findOrFail($budgetLineId);
        $status = $this->status($user, $period, $line->id);

        $status->fill(['amount_actual' => $amount === $line->amount ? null : $amount, 'skipped_on' => null])->save();
    }

    private function status(User $user, Period $period, int $budgetLineId): PeriodLineStatus
    {
        $status = $period->lineStatuses()->where('budget_line_id', $budgetLineId)->first();

        if ($status instanceof PeriodLineStatus) {
            return $status;
        }

        $status = new PeriodLineStatus(['period_id' => $period->id, 'budget_line_id' => $budgetLineId]);
        $status->user_id = $user->id;

        return $status;
    }
}
