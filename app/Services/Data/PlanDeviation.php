<?php

namespace App\Services\Data;

use App\Enums\DeviationReason;

/**
 * How far the month is from its plan: the leftover if every budget is spent in full, against
 * the leftover the plan expects. Only overspending, skipped fixed items and pocket moves make
 * them differ; the items say which and by how much, and add up to amount().
 */
final readonly class PlanDeviation
{
    /**
     * @param  list<DeviationItem>  $items
     */
    public function __construct(
        public int $plannedLeftover,
        public int $expectedLeftover,
        public array $items,
    ) {}

    /**
     * Positive: better than the plan; negative: worse; 0: on plan.
     */
    public function amount(): int
    {
        return $this->expectedLeftover - $this->plannedLeftover;
    }

    public function isOnPlan(): bool
    {
        return $this->amount() === 0;
    }

    /**
     * How much of a shortfall against the plan comes from overspending (positive, 0 if none).
     * Money put into pockets is a choice, not overspending: it is left out here.
     */
    public function overspentShortfall(): int
    {
        if ($this->amount() >= 0) {
            return 0;
        }

        $overspent = 0;

        foreach ($this->items as $item) {
            if ($item->reason === DeviationReason::Overspent) {
                $overspent -= $item->amount;
            }
        }

        return min($overspent, -$this->amount());
    }

    /**
     * Money put into pockets from this month (positive).
     */
    public function toPockets(): int
    {
        $total = 0;

        foreach ($this->items as $item) {
            if ($item->reason === DeviationReason::ToPockets) {
                $total -= $item->amount;
            }
        }

        return $total;
    }
}
