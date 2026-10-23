<?php

namespace App\Actions\Month;

use App\Enums\CloseDecision;
use App\Enums\DueState;
use App\Enums\PeriodStatus;
use App\Enums\PocketMovementType;
use App\Models\Period;
use App\Models\PeriodClose;
use App\Models\Pocket;
use App\Models\User;
use App\Services\BudgetCalculator;
use App\Services\Data\CloseSummary;
use App\Services\Data\MonthLine;
use App\Services\MonthService;
use App\Services\PeriodCloser;
use App\Services\PeriodService;
use App\Services\PlanService;
use App\Support\Dates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The 2.0 closing: one screen, one decision about the leftover (or the deficit).
 *
 * Nothing moves unless the decision says so: "Félreteszem" adds to a goal, "Viszem" adds to the next
 * month's money, a deducted deficit takes it off, "Célból fedezem" takes it from a goal. Savings lines
 * are not moved here: a goal grows on the "Félretettem" tap (MarkLineDone), which the screen asks
 * for first. ReopenPeriod undoes a closing until the month's regular end.
 */
final readonly class CloseMonth
{
    public function __construct(
        private MonthService $months,
        private PeriodService $periods,
        private PeriodCloser $closer,
        private PlanService $plans,
        private BudgetCalculator $calculator,
    ) {}

    public function summary(User $user, Period $period, ?int $incomeActual = null): CloseSummary
    {
        $lines = $this->months->lines($period);
        $view = $this->months->view($user, $period, $period->ends_on);

        return new CloseSummary(
            income: $incomeActual ?? $period->income(),
            extraIncome: $view->extraIncome,
            carriedOver: $period->carried_over,
            fixedTotal: $view->fixedTotal,
            savingsTotal: array_sum(array_map(fn (MonthLine $line): int => $line->isSavings() ? $line->counted() : 0, $lines)),
            spent: array_sum($view->spentByCategory),
            unconfirmedSavings: array_values(array_filter(
                $view->lines,
                fn (MonthLine $line): bool => $line->isSavings() && $line->goalId !== null && ! in_array($line->state, [DueState::Done, DueState::Skipped], true),
            )),
        );
    }

    /**
     * @param  int|null  $toGoal  with Save: how much goes to the goal; the rest is carried over (the split)
     */
    public function handle(User $user, Period $period, CloseDecision $decision, ?int $goalId = null, ?int $toGoal = null, ?int $incomeActual = null): PeriodClose
    {
        if (! $period->isOpen()) {
            throw ValidationException::withMessages(['period' => __('This month is closed.')]);
        }

        if (! $this->closer->canClose($user, $period)) {
            throw ValidationException::withMessages(['period' => __('The month can be closed from :date.', ['date' => Dates::short($this->closer->closableFrom($period))])]);
        }

        return DB::transaction(function () use ($user, $period, $decision, $goalId, $toGoal, $incomeActual): PeriodClose {
            $summary = $this->summary($user, $period, $incomeActual);
            $leftover = $summary->leftover();
            $goal = $goalId !== null ? $user->pockets()->find($goalId) : null;

            if (! in_array($decision, $summary->decisions($goal instanceof Pocket), true) || (in_array($decision, [CloseDecision::Save, CloseDecision::FromGoal], true) && ! $goal instanceof Pocket)) {
                throw ValidationException::withMessages(['decision' => __('Choose what happens to the leftover.')]);
            }

            $toGoal = $decision === CloseDecision::Save ? min($leftover, max(0, $toGoal ?? $leftover)) : 0;
            $carried = match ($decision) {
                CloseDecision::Save => $leftover - $toGoal,
                CloseDecision::Carry, CloseDecision::Deduct => $leftover,
                default => 0,
            };

            [$nextStart, $nextEnd] = $this->closer->nextBounds($user, $period);
            $closesEarly = $nextStart->lessThan($period->ends_on);
            $closedOn = ($closesEarly ? $nextStart : $period->ends_on)->toDateString();
            $regularEndsOn = $period->ends_on->toDateString();
            $movementIds = [];

            if ($goal instanceof Pocket && $toGoal > 0) {
                $movementIds[] = $this->move($user, $goal, $period, $toGoal, PocketMovementType::Deposit, $closedOn, __('Leftover'));
            }

            if ($goal instanceof Pocket && $decision === CloseDecision::FromGoal) {
                $movementIds[] = $this->move($user, $goal, $period, $leftover, PocketMovementType::Withdraw, $closedOn, __('Covering the deficit'));
            }

            $period->update([
                'ends_on' => $closedOn,
                'income_actual' => $summary->income,
                'status' => PeriodStatus::Closed,
                'plan_snapshot' => $this->calculator->snapshot($this->plans->linesFor($period)),
            ]);

            $close = new PeriodClose([
                'period_id' => $period->id,
                'planned_total' => $summary->fixedTotal,
                'actual_total' => $summary->fixedTotal + $summary->spent,
                'leftover' => $leftover,
                'decision' => $decision,
                'to_goal' => $toGoal,
                'goal_id' => $goal?->id,
                'carried_over' => $carried,
                'breakdown' => [
                    'income' => $summary->income,
                    'extra_income' => $summary->extraIncome,
                    'carried_in' => $summary->carriedOver,
                    'fixed' => $summary->fixedTotal,
                    'savings' => $summary->savingsTotal,
                    'spent' => $summary->spent,
                    'undo' => ['regular_ends_on' => $regularEndsOn, 'movement_ids' => $movementIds, 'created_pocket_id' => null],
                ],
            ]);
            $close->user_id = $user->id;
            $close->save();

            $next = $closesEarly ? $this->openEarly($user, $nextStart, $nextEnd) : $this->periods->forDate($user, $nextStart);
            $next->update(['carried_over' => $carried]);

            return $close;
        });
    }

    private function openEarly(User $user, CarbonImmutable $start, CarbonImmutable $end): Period
    {
        $next = $user->periods()->whereDate('starts_on', '>', $start->toDateString())->oldest('starts_on')->first();

        if ($next instanceof Period) {
            $next->update(['starts_on' => $start->toDateString()]);

            return $next;
        }

        return $this->periods->open($user, $start, $end, $user->settings()->income);
    }

    private function move(User $user, Pocket $goal, Period $period, int $amount, PocketMovementType $type, string $date, string $note): int
    {
        $movement = $user->pocketMovements()->create([
            'pocket_id' => $goal->id,
            'period_id' => $period->id,
            'amount' => $amount,
            'type' => $type,
            'occurred_on' => $date,
            'note' => $note,
        ]);

        $goal->increment('balance', $amount);

        return $movement->id;
    }
}
