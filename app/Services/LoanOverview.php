<?php

namespace App\Services;

use App\Enums\PrepayMode;
use App\Models\BudgetLine;
use App\Models\Loan;
use App\Models\Pocket;
use App\Models\User;
use App\Services\Data\LoanPlan;
use App\Services\Data\LoanSummary;
use Carbon\CarbonImmutable;

/**
 * Reads a user's loans for the planner and the "Következő előtörlesztés" cards (S23).
 */
final readonly class LoanOverview
{
    public function __construct(private LoanPlanner $planner) {}

    /**
     * @param  int|null  $monthlySaving  plan with this saving instead of the current one (the planner's slider)
     */
    public function summary(User $user, Loan $loan, CarbonImmutable $today, ?int $monthlySaving = null, ?int $step = null, ?PrepayMode $mode = null): LoanSummary
    {
        $line = $user->budgetLines()->with('category')->where('loan_id', $loan->id)->whereNull('active_to')->first();
        $pocket = $loan->pockets()->first();
        $saving = $pocket instanceof Pocket ? (int) $user->budgetLines()->where('pocket_id', $pocket->id)->whereNull('active_to')->sum('amount') : 0;

        return new LoanSummary($loan, $line, $pocket, $saving, $this->plan($loan, $line, $pocket, $today, $monthlySaving ?? $saving, $step, $mode ?? $loan->prepay_mode));
    }

    /**
     * The loans with a prepayment being saved up, for the Today and Month cards.
     *
     * @return list<LoanSummary>
     */
    public function saving(User $user, CarbonImmutable $today): array
    {
        $loans = $user->loans()->whereHas('pockets', fn ($query) => $query->where('prepay_step', '>', 0))->orderBy('id')->get();

        return array_values(array_filter(
            $loans->map(fn (Loan $loan): LoanSummary => $this->summary($user, $loan, $today))->all(),
            fn (LoanSummary $summary): bool => $summary->isSaving(),
        ));
    }

    private function plan(Loan $loan, ?BudgetLine $line, ?Pocket $pocket, CarbonImmutable $today, int $monthlySaving, ?int $step, PrepayMode $mode): LoanPlan
    {
        // The next installment: this month while its day is still ahead, otherwise next month.
        $dueDay = $line?->due_day;
        $firstMonth = $dueDay !== null && $today->day <= $dueDay ? $today->startOfMonth() : $today->startOfMonth()->addMonth();

        return $this->planner->plan(
            $loan->principal_balance,
            $loan->thm ?? 0.0,
            $loan->installment,
            $loan->remaining_months ?? 1,
            $monthlySaving,
            $step ?? ($pocket->prepay_step ?? 0),
            $mode,
            $firstMonth,
            $pocket->balance ?? 0,
        );
    }
}
