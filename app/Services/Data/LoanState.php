<?php

namespace App\Services\Data;

final readonly class LoanState
{
    public function __construct(
        public int $principal,
        public int $installment,
        public ?int $remainingMonths,
    ) {}
}
