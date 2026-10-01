<?php

namespace App\Services;

use App\Enums\LineType;
use App\Models\BudgetLine;
use App\Services\Data\PlanLine;
use App\Services\Data\PlanSummary;
use Carbon\CarbonInterface;

final class BudgetCalculator
{
    /**
     * Turn budget lines (with their category loaded) into plan lines active in the window.
     *
     * @param  iterable<BudgetLine>  $lines
     * @return list<PlanLine>
     */
    public function planLines(iterable $lines, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $planLines = [];

        foreach ($lines as $line) {
            $category = $line->category;

            if ($category === null) {
                continue;
            }

            if ($from instanceof CarbonInterface && $to instanceof CarbonInterface && ! $line->isActiveBetween($from, $to)) {
                continue;
            }

            $planLines[] = new PlanLine(
                lineId: $line->id,
                categoryId: $line->category_id,
                categoryName: $category->name,
                type: $category->type,
                amount: $line->amount,
                amountAvg: $line->amount_avg,
                amountMax: $line->amount_max,
                calcMode: $line->calc_mode,
                dueDay: $line->due_day,
                pocketId: $line->pocket_id,
                loanId: $line->loan_id,
            );
        }

        return $planLines;
    }

    /**
     * Planned leftover: income minus every planned line, variable lines by their calc mode.
     *
     * @param  list<PlanLine>  $lines
     */
    public function summarize(array $lines, int $income): PlanSummary
    {
        $totals = array_fill_keys(array_map(fn (LineType $type): string => $type->value, LineType::cases()), 0);

        foreach ($lines as $line) {
            $totals[$line->type->value] += $line->planned();
        }

        $expenses = array_sum($totals);

        return new PlanSummary(
            income: $income,
            totalsByType: $totals,
            totalExpenses: $expenses,
            leftover: $income - $expenses,
        );
    }

    /**
     * @param  list<PlanLine>  $lines
     * @return list<array<string, mixed>>
     */
    public function snapshot(array $lines): array
    {
        return array_map(fn (PlanLine $line): array => $line->toArray(), $lines);
    }

    /**
     * @param  array<array-key, mixed>|null  $snapshot
     * @return list<PlanLine>
     */
    public function fromSnapshot(?array $snapshot): array
    {
        $lines = [];

        foreach ($snapshot ?? [] as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $lines[] = PlanLine::fromArray($row);
            }
        }

        return $lines;
    }
}
