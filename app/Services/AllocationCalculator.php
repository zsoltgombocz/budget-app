<?php

namespace App\Services;

use App\Services\Data\Allocation;
use App\Services\Data\MonthEndForecast;
use App\Services\Data\PlanLine;
use App\Services\Data\PlanSummary;
use InvalidArgumentException;

final class AllocationCalculator
{
    /**
     * Split a period's leftover.
     *
     * A negative leftover leaves nothing to split: nothing moves, the gap is only reported.
     * Positive leftover goes to the reserve first: min(leftover × pct, room to target), or
     * min(fixed amount, leftover, room to target) when a fixed amount is set; the rest goes to
     * the user's surplus target (investment account or pocket).
     *
     * @param  bool  $hasReserve  whether the user has a reserve pocket at all
     * @param  int|null  $reserveTarget  null means the reserve has no cap
     * @param  int|null  $reserveFixed  a fixed amount instead of the share, null to use the share
     */
    public function allocate(int $leftover, int $reservePct, bool $hasReserve, int $reserveBalance = 0, ?int $reserveTarget = null, ?int $reserveFixed = null): Allocation
    {
        if ($reservePct < 0 || $reservePct > 100) {
            throw new InvalidArgumentException('Reserve percentage must be between 0 and 100.');
        }

        if ($leftover < 0) {
            return new Allocation(
                leftover: $leftover,
                toReserve: 0,
                toSurplus: 0,
                fromReserve: 0,
                uncovered: -$leftover,
            );
        }

        $toReserve = 0;

        if ($hasReserve) {
            // A fixed amount, when set, replaces the share: at most that much goes to the reserve.
            $share = $reserveFixed !== null ? min($leftover, max(0, $reserveFixed)) : intdiv($leftover * $reservePct, 100);
            $room = $reserveTarget === null ? $share : max(0, $reserveTarget - $reserveBalance);
            $toReserve = min($share, $room);
        }

        return new Allocation(
            leftover: $leftover,
            toReserve: $toReserve,
            toSurplus: $leftover - $toReserve,
            fromReserve: 0,
            uncovered: 0,
        );
    }

    /**
     * The month-end picture of a plan, before anything is spent: income minus the planned
     * lines leaves the expected leftover, split by the leftover rule. The monthly saving into
     * the reserve pocket is shown on its own, and counts towards the reserve balance first.
     *
     * @param  list<PlanLine>  $lines
     */
    public function monthEnd(PlanSummary $summary, array $lines, int $reservePct, ?int $reservePocketId, int $reserveBalance = 0, ?int $reserveTarget = null, ?int $reserveFixed = null): MonthEndForecast
    {
        $reserveMonthly = 0;

        foreach ($lines as $line) {
            if ($reservePocketId !== null && $line->pocketId === $reservePocketId) {
                $reserveMonthly += $line->planned();
            }
        }

        return new MonthEndForecast(
            income: $summary->income,
            planned: $summary->totalExpenses - $reserveMonthly,
            reserveMonthly: $reserveMonthly,
            leftover: $summary->leftover,
            allocation: $this->allocate(
                leftover: max(0, $summary->leftover),
                reservePct: $reservePct,
                hasReserve: $reservePocketId !== null,
                reserveBalance: $reserveBalance + $reserveMonthly,
                reserveTarget: $reserveTarget,
                reserveFixed: $reserveFixed,
            ),
            hasReserve: $reservePocketId !== null,
        );
    }
}
