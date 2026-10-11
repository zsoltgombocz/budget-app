<?php

namespace App\Services;

use App\Enums\LineType;
use App\Models\BudgetLine;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * What editing the plan does to the period that is already running: an open period reads the
 * live plan from its first day, so a change is never "from now on" only.
 */
final readonly class PlanChangeImpact
{
    public function __construct(private PeriodService $periods) {}

    /**
     * First day of the current period when a plan change reaches it: the period is open (a
     * closed one keeps its frozen snapshot) and, for a single line, the line is active in it.
     */
    public function appliesFrom(User $user, ?BudgetLine $line = null): ?CarbonImmutable
    {
        $period = $this->periods->current($user);

        if (! $period->isOpen()) {
            return null;
        }

        if ($line instanceof BudgetLine && ! $line->isActiveBetween($period->starts_on, $period->ends_on)) {
            return null;
        }

        return $period->starts_on;
    }

    /**
     * Spending already recorded on the line's category in the open current period that would be
     * counted differently under the new type, or null when the change needs no warning.
     *
     * Only a switch between a tracked type (variable: the recorded spending counts) and a
     * planned one (everything else: the planned amount counts) changes how it is counted.
     * Spending paid from a pocket never uses the budget, so it is left out.
     */
    public function spendingRecountedBy(User $user, BudgetLine $line, LineType $newType): ?int
    {
        $currentType = $line->category?->type;

        if (! $currentType instanceof LineType || $currentType->isTracked() === $newType->isTracked()) {
            return null;
        }

        $period = $this->periods->current($user);

        if (! $period->isOpen()) {
            return null;
        }

        $spending = $period->transactions()->where('category_id', $line->category_id)->whereNull('pocket_id');

        return $spending->exists() ? (int) $spending->sum('amount') : null;
    }
}
