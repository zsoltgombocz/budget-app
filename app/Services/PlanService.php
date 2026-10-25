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
    public function __construct(private BudgetCalculator $calculator, private Plans $plans) {}

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

        $statuses = $period->lineStatuses()->where(fn ($query) => $query->whereNotNull('skipped_on')->orWhereNotNull('amount_actual'))->get();
        $skipped = $statuses->whereNotNull('skipped_on')->pluck('budget_line_id')->flip();
        $amounts = $statuses->whereNotNull('amount_actual')->pluck('amount_actual', 'budget_line_id');
        $user = User::query()->find($period->user_id);
        $lines = $user instanceof User ? $this->withoutLocked($user, $this->liveLines($user, $period->starts_on, $period->ends_on)) : [];

        return array_map(function (PlanLine $line) use ($skipped, $amounts): PlanLine {
            if ($line->lineId === null) {
                return $line;
            }

            $amount = $amounts->get($line->lineId);
            $line = is_int($amount) ? $line->withAmount($amount) : $line;

            return $skipped->has($line->lineId) ? $line->withSkipped(true) : $line;
        }, $lines);
    }

    /**
     * Leave out the lines only Plusz counts (putting money aside) for a user on Alap.
     *
     * @param  list<PlanLine>  $lines
     * @return list<PlanLine>
     */
    public function withoutLocked(User $user, array $lines): array
    {
        $locked = $this->plans->lockedLineTypes($user);

        return $locked === [] ? $lines : array_values(array_filter($lines, fn (PlanLine $line): bool => ! in_array($line->type, $locked, true)));
    }

    public function summaryFor(Period $period): PlanSummary
    {
        return $this->calculator->summarize($this->linesFor($period), $period->income());
    }
}
