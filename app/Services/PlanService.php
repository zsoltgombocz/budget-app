<?php

namespace App\Services;

use App\Models\BudgetLine;
use App\Models\Period;
use App\Models\User;
use App\Services\Data\PlanLine;
use App\Services\Data\PlanSummary;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

final readonly class PlanService
{
    public function __construct(private BudgetCalculator $calculator) {}

    /**
     * @return Collection<int, BudgetLine>
     */
    public function budgetLines(User|int $user): Collection
    {
        return BudgetLine::query()
            ->where('user_id', $user instanceof User ? $user->id : $user)
            ->with('category')
            ->whereHas('category')
            ->get()
            ->sortBy(fn (BudgetLine $line): array => [$line->category?->sort, $line->category?->name, $line->id])
            ->values();
    }

    /**
     * @return list<PlanLine>
     */
    public function liveLines(User|int $user, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        return $this->calculator->planLines($this->budgetLines($user), $from, $to);
    }

    /**
     * Plan of a period: the frozen snapshot once closed, the live plan while open.
     *
     * @return list<PlanLine>
     */
    public function linesFor(Period $period): array
    {
        if (! $period->isOpen()) {
            return $this->calculator->fromSnapshot($period->plan_snapshot);
        }

        return $this->liveLines($period->user_id, $period->starts_on, $period->ends_on);
    }

    public function summaryFor(Period $period): PlanSummary
    {
        return $this->calculator->summarize($this->linesFor($period), $period->income());
    }
}
