<?php

namespace App\Services\Data;

use Carbon\CarbonImmutable;

/**
 * "Mikor leszek hitelmentes?": the loan with and without a monthly prepayment saving. Estimates.
 */
final readonly class LoanPlan
{
    /**
     * @param  list<CarbonImmutable>  $prepayments  the months a step is reached and prepaid
     */
    public function __construct(
        public CarbonImmutable $endWithout,
        public CarbonImmutable $endWith,
        public int $interestWithout,
        public int $interestWith,
        public array $prepayments,
        /** The installment after the first prepayment when it lowers the installment; null otherwise. */
        public ?int $installmentAfterFirst,
        /** The installment a year from now (0: paid off by then). */
        public int $installmentInYear = 0,
    ) {}

    public function savedInterest(): int
    {
        return max(0, $this->interestWithout - $this->interestWith);
    }

    public function monthsSooner(): int
    {
        return max(0, (int) $this->endWith->diffInMonths($this->endWithout));
    }
}
