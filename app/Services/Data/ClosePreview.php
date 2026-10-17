<?php

namespace App\Services\Data;

/**
 * Everything the closing wizard shows before the user confirms.
 */
final readonly class ClosePreview
{
    /**
     * @param  list<array{category_id: int, name: string, type: string, planned: int, actual: int, diff: int, deviates: bool, skipped: bool}>  $categories
     * @param  list<array{pocket_id: int, name: string, amount: int}>  $pocketDeposits
     * @param  list<array{pocket_id: int, name: string, loan_id: int|null, balance: int, step: int}>  $prepayReady
     * @param  array{type: string|null, id: int|null, name: string|null}  $surplusTarget
     * @param  list<array{pocket_id: int|null, name: string, is_reserve: bool, monthly: int, leftover: int, cover: int, total: int, balance_after: int}>  $pocketMoves
     */
    public function __construct(
        public int $incomePlanned,
        public int $incomeActual,
        public int $topUps,
        public int $plannedTotal,
        public int $actualTotal,
        public array $categories,
        public Allocation $allocation,
        public ?int $reservePocketId,
        /** The reserve's balance after the closing: monthly deposit, leftover share and any deficit cover included. */
        public ?int $reserveBalanceAfter,
        public array $surplusTarget,
        public array $pocketDeposits,
        public array $prepayReady,
        /** Every pocket the closing moves money in: monthly saving + leftover share + deficit cover = total → new balance. */
        public array $pocketMoves = [],
        /** What the leftover rule alone puts into the reserve, before a split chosen by hand. */
        public int $ruleToReserve = 0,
        public ?int $reserveTarget = null,
        /** How much the reserve still needs to reach its target after the monthly saving; null without a target. */
        public ?int $reserveRoom = null,
    ) {}

    /**
     * What would be left if the period went exactly to plan.
     */
    public function plannedLeftover(): int
    {
        return $this->incomeActual - $this->plannedTotal;
    }

    public function leftover(): int
    {
        return $this->incomeActual + $this->topUps - $this->actualTotal;
    }

    /**
     * @return array<string, mixed>
     */
    public function toBreakdown(): array
    {
        return [
            'income_planned' => $this->incomePlanned,
            'income_actual' => $this->incomeActual,
            'top_ups' => $this->topUps,
            'categories' => $this->categories,
            'allocation' => [
                'to_reserve' => $this->allocation->toReserve,
                'to_surplus' => $this->allocation->toSurplus,
                'from_reserve' => $this->allocation->fromReserve,
                'uncovered' => $this->allocation->uncovered,
            ],
            'surplus_target' => $this->surplusTarget,
            'pocket_deposits' => $this->pocketDeposits,
            'prepay_ready' => $this->prepayReady,
            'pocket_moves' => $this->pocketMoves,
        ];
    }
}
