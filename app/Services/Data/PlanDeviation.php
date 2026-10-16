<?php

namespace App\Services\Data;

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
}
