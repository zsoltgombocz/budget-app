<?php

namespace App\Services\Data;

use Carbon\CarbonImmutable;

final readonly class FixedItem
{
    public function __construct(
        public PlanLine $line,
        public ?CarbonImmutable $dueOn,
        public bool $paid,
    ) {}

    public function isOverdue(CarbonImmutable $today): bool
    {
        return ! $this->paid && $this->dueOn instanceof CarbonImmutable && $this->dueOn->lessThanOrEqualTo($today);
    }
}
