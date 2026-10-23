<?php

namespace App\Services\Data;

use App\Enums\DueState;
use App\Enums\LineType;
use Carbon\CarbonImmutable;

/**
 * One fixed line of a month as the 2.0 screens see it: what it counts as, when it is due and
 * whether it was marked. $amount is what the month counts (its own amount, or "Más összeg volt").
 */
final readonly class MonthLine
{
    public function __construct(
        public int $lineId,
        public string $name,
        public LineType $type,
        public int $amount,
        public ?CarbonImmutable $dueOn = null,
        public bool $manualPay = false,
        public bool $done = false,
        public bool $skipped = false,
        public ?int $goalId = null,
        public ?int $loanId = null,
        public ?DueState $state = null,
    ) {}

    public function withState(DueState $state): self
    {
        return new self($this->lineId, $this->name, $this->type, $this->amount, $this->dueOn, $this->manualPay, $this->done, $this->skipped, $this->goalId, $this->loanId, $state);
    }

    /**
     * What the month takes off for it: nothing when it is not this month.
     */
    public function counted(): int
    {
        return $this->skipped ? 0 : $this->amount;
    }

    public function isSavings(): bool
    {
        return $this->type === LineType::Sinking;
    }
}
