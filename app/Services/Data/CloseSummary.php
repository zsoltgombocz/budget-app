<?php

namespace App\Services\Data;

use App\Enums\CloseDecision;

/**
 * The closing screen's numbers: income − fixed lines (savings included) − spent = leftover.
 */
final readonly class CloseSummary
{
    /**
     * @param  list<MonthLine>  $unconfirmedSavings  savings lines tied to a goal without a "Félretettem" this month
     */
    public function __construct(
        public int $income,
        public int $extraIncome,
        public int $carriedOver,
        public int $fixedTotal,
        public int $savingsTotal,
        public int $spent,
        public array $unconfirmedSavings,
    ) {}

    public function leftover(): int
    {
        return $this->income + $this->extraIncome + $this->carriedOver - $this->fixedTotal - $this->spent;
    }

    public function isDeficit(): bool
    {
        return $this->leftover() < 0;
    }

    /**
     * The decisions the screen offers for this leftover.
     *
     * @return list<CloseDecision>
     */
    public function decisions(bool $hasGoal): array
    {
        $leftover = $this->leftover();

        return match (true) {
            $leftover > 0 => $hasGoal ? [CloseDecision::Save, CloseDecision::Carry, CloseDecision::Note] : [CloseDecision::Carry, CloseDecision::Note],
            $leftover < 0 => $hasGoal ? [CloseDecision::Deduct, CloseDecision::Covered, CloseDecision::FromGoal] : [CloseDecision::Deduct, CloseDecision::Covered],
            default => [CloseDecision::Note],
        };
    }
}
