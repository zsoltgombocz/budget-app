<?php

namespace App\Services;

use App\Enums\LineType;
use App\Models\BudgetLine;
use App\Models\Period;
use App\Models\PeriodLineStatus;
use App\Models\User;
use App\Services\Data\MonthLine;
use App\Services\Data\MonthView;
use App\Services\Data\PlanLine;
use Carbon\CarbonImmutable;

/**
 * Reads a month from the database into the 2.0 model (MonthBudget, FixedLines).
 */
final readonly class MonthService
{
    public function __construct(
        private PeriodService $periods,
        private PlanService $plans,
        private OverviewService $overview,
        private MonthBudget $budget,
        private FixedLines $fixedLines,
    ) {}

    public function current(User $user): MonthView
    {
        $today = $this->periods->today($user->settings());

        return $this->view($user, $this->periods->forDate($user, $today), $today);
    }

    public function view(User $user, Period $period, CarbonImmutable $today): MonthView
    {
        $day = $this->clampToPeriod($period, $today);
        $lines = $this->fixedLines->states($this->lines($period), $day);
        $fixedTotal = array_sum(array_map(fn (MonthLine $line): int => $line->counted(), $lines));
        $extraIncome = (int) $period->extraIncomes()->sum('amount');
        $spentByCategory = $this->spentByCategory($period);
        $spentToday = (int) $period->transactions()->whereNull('pocket_id')->whereDate('occurred_on', $day->toDateString())->sum('amount');

        $state = $this->budget->state(
            income: $period->income(),
            fixedTotal: $fixedTotal,
            extraIncome: $extraIncome,
            carriedOver: $period->carried_over,
            spentBeforeToday: array_sum($spentByCategory) - $spentToday,
            spentToday: $spentToday,
            totalDays: $period->totalDays(),
            daysLeft: max(1, $period->remainingDays($day)),
        );

        return new MonthView(
            period: $period,
            today: $day,
            state: $state,
            lines: $lines,
            fixedSummary: $this->fixedLines->summary($lines),
            income: $period->income(),
            fixedTotal: $fixedTotal,
            extraIncome: $extraIncome,
            spentByCategory: $spentByCategory,
            noSpendToday: $user->dayMarks()->whereDate('date', $day->toDateString())->exists(),
        );
    }

    /**
     * The month's fixed lines (fixed, loan, savings; variable budgets are not part of 2.0) with
     * what was marked for them this month.
     *
     * @return list<MonthLine>
     */
    public function lines(Period $period): array
    {
        $planLines = array_values(array_filter($this->plans->linesFor($period), fn (PlanLine $line): bool => $line->type !== LineType::Variable));
        $ids = array_values(array_filter(array_map(fn (PlanLine $line): ?int => $line->lineId, $planLines)));
        $manual = BudgetLine::query()->whereIn('id', $ids)->pluck('manual_pay', 'id');
        $statuses = $period->lineStatuses()->get()->keyBy('budget_line_id');
        $lines = [];

        foreach ($planLines as $line) {
            if ($line->lineId === null) {
                continue;
            }

            $status = $statuses->get($line->lineId);

            $lines[] = new MonthLine(
                lineId: $line->lineId,
                name: $line->categoryName,
                type: $line->type === LineType::Transfer ? LineType::Fixed : $line->type,
                amount: $status instanceof PeriodLineStatus && $status->amount_actual !== null ? $status->amount_actual : $line->planned(),
                dueOn: $this->overview->dueDate($period, $line->dueDay),
                manualPay: (bool) ($manual[$line->lineId] ?? false),
                done: $status instanceof PeriodLineStatus && $status->paid_on !== null,
                skipped: $status instanceof PeriodLineStatus && $status->skipped_on !== null,
                goalId: $line->type === LineType::Sinking ? $line->pocketId : null,
                loanId: $line->loanId,
            );
        }

        return $lines;
    }

    /**
     * Spending recorded this month by category (paid from a goal does not count).
     *
     * @return array<int, int>
     */
    public function spentByCategory(Period $period): array
    {
        $spent = [];

        foreach ($period->transactions()->whereNull('pocket_id')->selectRaw('category_id, sum(amount) as total')->groupBy('category_id')->toBase()->get() as $row) {
            if (is_numeric($row->category_id) && is_numeric($row->total)) {
                $spent[(int) $row->category_id] = (int) $row->total;
            }
        }

        return $spent;
    }

    private function clampToPeriod(Period $period, CarbonImmutable $today): CarbonImmutable
    {
        return match (true) {
            $today->lessThan($period->starts_on) => $period->starts_on,
            $today->greaterThan($period->ends_on) => $period->ends_on,
            default => $today,
        };
    }
}
