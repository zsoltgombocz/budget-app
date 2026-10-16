<?php

namespace App\Services\Data;

use App\Enums\DeviationReason;

/**
 * One reason the month deviates from its plan, with its signed effect on the leftover
 * (negative: less is left than planned).
 */
final readonly class DeviationItem
{
    public function __construct(
        public DeviationReason $reason,
        public int $amount,
        public string $name = '',
        public bool $withoutBudget = false,
    ) {}

    public function label(): string
    {
        return match ($this->reason) {
            DeviationReason::Overspent => $this->withoutBudget
                ? __(':name, without a budget', ['name' => $this->name])
                : __(':name over budget', ['name' => $this->name]),
            DeviationReason::Skipped => __(':name skipped', ['name' => $this->name]),
            DeviationReason::ToPockets => __('Put into pockets'),
            DeviationReason::FromPockets => __('From pockets into the budget'),
        };
    }
}
