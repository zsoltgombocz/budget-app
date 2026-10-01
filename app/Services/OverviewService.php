<?php

namespace App\Services;

use App\Enums\LineType;
use App\Models\Period;
use App\Models\User;
use App\Services\Data\FixedItem;
use App\Services\Data\Overview;
use App\Services\Data\PlanLine;
use Carbon\CarbonImmutable;

final readonly class OverviewService
{
    public function __construct(
        private PeriodService $periods,
        private PlanService $plans,
        private ForecastService $forecasts,
    ) {}

    public function forUser(User $user): Overview
    {
        $today = $this->periods->today($user->settings());
        $period = $this->periods->forDate($user, $today);

        return $this->forPeriod($user, $period, $today);
    }

    public function forPeriod(User $user, Period $period, CarbonImmutable $today): Overview
    {
        $lines = $this->plans->linesFor($period);

        $spent = [];

        // Spending paid from a pocket does not use up the budget.
        foreach ($period->transactions()->whereNull('pocket_id')->selectRaw('category_id, sum(amount) as total')->groupBy('category_id')->toBase()->get() as $row) {
            if (is_numeric($row->category_id) && is_numeric($row->total)) {
                $spent[(int) $row->category_id] = (int) $row->total;
            }
        }

        $categoryNames = [];

        foreach ($user->categories()->withTrashed()->whereIn('id', array_keys($spent))->get(['id', 'name']) as $category) {
            $categoryNames[$category->id] = $category->name;
        }

        $forecast = $this->forecasts->forecast(
            lines: $lines,
            income: $this->periods->availableIncome($period),
            spentByCategory: $spent,
            totalDays: $period->totalDays(),
            elapsedDays: max(1, $period->elapsedDays($today)),
            categoryNames: $categoryNames,
        );

        return new Overview(
            period: $period,
            today: $today,
            forecast: $forecast,
            fixedItems: $this->fixedItems($period, $lines),
            spentToday: (int) $period->transactions()->whereDate('occurred_on', $today->toDateString())->sum('amount'),
            noSpendMarked: $user->dayMarks()->whereDate('date', $today->toDateString())->exists(),
        );
    }

    /**
     * Lines that are fulfilled by the plan (transfers, loans, fixed costs, sinking funds),
     * with their due date in the period and whether they were ticked off.
     *
     * @param  list<PlanLine>  $lines
     * @return list<FixedItem>
     */
    public function fixedItems(Period $period, array $lines): array
    {
        $paid = $period->lineStatuses()->whereNotNull('paid_on')->pluck('budget_line_id')->flip();
        $items = [];

        foreach ($lines as $line) {
            if ($line->type === LineType::Variable || $line->planned() <= 0) {
                continue;
            }

            $items[] = new FixedItem(
                line: $line,
                dueOn: $this->dueDate($period, $line->dueDay),
                paid: $line->lineId !== null && $paid->has($line->lineId),
            );
        }

        usort($items, fn (FixedItem $a, FixedItem $b): int => [$a->paid, $a->dueOn->timestamp ?? PHP_INT_MAX] <=> [$b->paid, $b->dueOn->timestamp ?? PHP_INT_MAX]);

        return $items;
    }

    /**
     * The date inside the period that falls on the due day (clamped to short months).
     */
    public function dueDate(Period $period, ?int $dueDay): ?CarbonImmutable
    {
        if ($dueDay === null) {
            return null;
        }

        for ($month = $period->starts_on->startOfMonth(); $month->lessThanOrEqualTo($period->ends_on); $month = $month->addMonth()) {
            $date = $month->setDay(min($dueDay, $month->daysInMonth));

            if ($period->contains($date)) {
                return $date;
            }
        }

        return null;
    }
}
