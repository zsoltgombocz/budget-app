<?php

namespace App\Actions\Budget;

use App\Enums\LoanEventType;
use App\Enums\PocketMovementType;
use App\Enums\PrepayMode;
use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\Pocket;
use App\Models\User;
use App\Services\Data\LoanState;
use App\Services\LoanCalculator;
use App\Services\PeriodService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class RecordPrepayment
{
    public function __construct(
        private LoanCalculator $calculator,
        private PeriodService $periods,
    ) {}

    /**
     * Prepay a loan, optionally from a pocket. The bank either shortens the term or lowers the
     * installment ($mode; the loan's last choice when not given, and the choice is kept). A lower
     * installment flows into the loan's plan line, so the next period's leftover grows.
     */
    public function handle(User $user, Loan $loan, int $amount, ?Pocket $pocket = null, ?PrepayMode $mode = null): LoanEvent
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('The amount must be greater than zero.')]);
        }

        if ($amount > $loan->principal_balance) {
            throw ValidationException::withMessages(['amount' => __('At most the amount owed: :amount.', ['amount' => money($loan->principal_balance)])]);
        }

        if ($pocket instanceof Pocket && $pocket->balance < $amount) {
            throw ValidationException::withMessages(['amount' => __('The pocket does not have enough money.')]);
        }

        $mode ??= $loan->prepay_mode;

        return DB::transaction(function () use ($user, $loan, $amount, $pocket, $mode): LoanEvent {
            $after = $this->calculator->afterPrepayment(
                new LoanState($loan->principal_balance, $loan->installment, $loan->remaining_months),
                min($amount, $loan->principal_balance),
                $mode,
                $loan->thm,
            );

            $today = $this->periods->today($user->settings());
            $period = $this->periods->forDate($user, $today);

            // This month's installment is already set: the new one starts with the next period.
            foreach ($loan->budgetLines()->get() as $line) {
                $status = $period->lineStatuses()->firstOrNew(['budget_line_id' => $line->id]);
                $status->user_id = $user->id;
                $status->amount_actual ??= $line->amount;
                $status->save();
            }

            $loan->update([
                'principal_balance' => $after->principal,
                'installment' => $after->installment,
                'remaining_months' => $after->remainingMonths,
                'prepay_mode' => $mode,
            ]);

            $loan->budgetLines()->update(['amount' => $loan->monthlyPayment()]);

            if ($pocket instanceof Pocket) {
                $user->pocketMovements()->create([
                    'pocket_id' => $pocket->id,
                    'period_id' => $this->periods->forDate($user, $today)->id,
                    'amount' => -$amount,
                    'type' => PocketMovementType::Prepay,
                    'occurred_on' => $today->toDateString(),
                    'note' => $loan->name,
                ]);
                $pocket->decrement('balance', $amount);
            }

            return $user->loanEvents()->create([
                'loan_id' => $loan->id,
                'type' => LoanEventType::Prepayment,
                'amount' => $amount,
                'principal_after' => $after->principal,
                'installment_after' => $after->installment,
                'remaining_months_after' => $after->remainingMonths,
                'occurred_on' => $today->toDateString(),
            ]);
        });
    }
}
