<?php

namespace App\Services\Data;

use App\Enums\LineStatus;
use Carbon\CarbonImmutable;

final readonly class FixedItem
{
    public function __construct(
        public PlanLine $line,
        public ?CarbonImmutable $dueOn,
        public bool $paid,
    ) {}

    /**
     * Marked as not paid in this period: it counts as 0 and is no longer due.
     */
    public function isSkipped(): bool
    {
        return ! $this->paid && $this->line->skipped;
    }

    public function status(): LineStatus
    {
        return match (true) {
            $this->paid => LineStatus::Paid,
            $this->isSkipped() => LineStatus::Skipped,
            default => LineStatus::Open,
        };
    }

    public function isOverdue(CarbonImmutable $today): bool
    {
        return $this->status() === LineStatus::Open && $this->dueOn instanceof CarbonImmutable && $this->dueOn->lessThanOrEqualTo($today);
    }
}
