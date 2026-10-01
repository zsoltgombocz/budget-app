<?php

namespace App\Services\Data;

use App\Enums\LineType;

final readonly class PlanSummary
{
    /**
     * @param  array<string, int>  $totalsByType  keyed by LineType value
     */
    public function __construct(
        public int $income,
        public array $totalsByType,
        public int $totalExpenses,
        public int $leftover,
    ) {}

    public function totalFor(LineType $type): int
    {
        return $this->totalsByType[$type->value] ?? 0;
    }
}
