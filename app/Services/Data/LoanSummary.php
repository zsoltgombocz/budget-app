<?php

namespace App\Services\Data;

use App\Models\BudgetLine;
use App\Models\Loan;
use App\Models\Pocket;

/**
 * One loan as the planner and the progress cards show it: its installment line, the pocket its
 * prepayment is set aside in (if any), the monthly saving, and the plan with that saving.
 */
final readonly class LoanSummary
{
    public function __construct(
        public Loan $loan,
        public ?BudgetLine $line,
        public ?Pocket $pocket,
        public int $monthlySaving,
        public LoanPlan $plan,
    ) {}

    public function step(): int
    {
        return $this->pocket->prepay_step ?? 0;
    }

    public function saved(): int
    {
        return $this->pocket->balance ?? 0;
    }

    /** The saving has reached the step: time to prepay in the bank. */
    public function isReady(): bool
    {
        return $this->pocket?->hasReachedPrepayStep() ?? false;
    }

    /** A prepayment is being saved up (or is still waiting to be recorded). */
    public function isSaving(): bool
    {
        return $this->pocket instanceof Pocket && $this->step() > 0 && ($this->monthlySaving > 0 || $this->saved() > 0);
    }
}
