<?php

namespace App\Services\Data;

/**
 * Everything the closing wizard shows before the user confirms.
 */
final readonly class ClosePreview
{
    /**
     * @param  list<array{category_id: int, name: string, type: string, planned: int, actual: int, diff: int, deviates: bool}>  $categories
     * @param  list<array{pocket_id: int, name: string, amount: int}>  $pocketDeposits
     * @param  list<array{pocket_id: int, name: string, loan_id: int|null, balance: int, step: int}>  $prepayReady
     * @param  array{type: string|null, id: int|null, name: string|null}  $surplusTarget
     */
    public function __construct(
        public int $incomePlanned,
        public int $incomeActual,
        public int $plannedTotal,
        public int $actualTotal,
        public array $categories,
        public Allocation $allocation,
        public ?int $reservePocketId,
        public array $surplusTarget,
        public array $pocketDeposits,
        public array $prepayReady,
    ) {}

    public function leftover(): int
    {
        return $this->incomeActual - $this->actualTotal;
    }

    /**
     * @return array<string, mixed>
     */
    public function toBreakdown(): array
    {
        return [
            'income_planned' => $this->incomePlanned,
            'income_actual' => $this->incomeActual,
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
        ];
    }
}
