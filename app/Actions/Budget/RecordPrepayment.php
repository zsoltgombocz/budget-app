<?php

namespace App\Actions\Budget;

use App\Enums\LoanEventType;
use App\Enums\PocketMovementType;
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
     * Prepay a loan, optionally from a pocket. The new installment flows into the
     * loan's plan line, so the next period's leftover grows.
     */
    public function handle(User $user, Loan $loan, int $amount, ?Pocket $pocket = null): LoanEvent
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('The amount must be greater than zero.')]);
        }

        if ($pocket instanceof Pocket && $pocket->balance < $amount) {
            throw ValidationException::withMessages(['amount' => __('The pocket does not have enough money.')]);
        }

        return DB::transaction(function () use ($user, $loan, $amount, $pocket): LoanEvent {
            $after = $this->calculator->afterPrepayment(
                new LoanState($loan->principal_balance, $loan->installment, $loan->remaining_months),
                min($amount, $loan->principal_balance),
                $loan->prepay_mode,
                $loan->thm,
            );

            $today = $this->periods->today($user->settings());
            $before = ['principal_before' => $loan->principal_balance, 'installment_before' => $loan->installment, 'remaining_months_before' => $loan->remaining_months];
            $movementId = null;

            $loan->update([
                'principal_balance' => $after->principal,
                'installment' => $after->installment,
                'remaining_months' => $after->remainingMonths,
            ]);

            $loan->budgetLines()->update(['amount' => $loan->monthlyPayment()]);

            if ($pocket instanceof Pocket) {
                $movementId = $user->pocketMovements()->create([
                    'pocket_id' => $pocket->id,
                    'period_id' => $this->periods->forDate($user, $today)->id,
                    'amount' => -$amount,
                    'type' => PocketMovementType::Prepay,
                    'occurred_on' => $today->toDateString(),
                    'note' => $loan->name,
                ])->id;
                $pocket->decrement('balance', $amount);
            }

            return $user->loanEvents()->create([
                'loan_id' => $loan->id,
                'type' => LoanEventType::Prepayment,
                'amount' => $amount,
                ...$before,
                'principal_after' => $after->principal,
                'installment_after' => $after->installment,
                'remaining_months_after' => $after->remainingMonths,
                'pocket_movement_id' => $movementId,
                'occurred_on' => $today->toDateString(),
            ]);
        });
    }

    /**
     * Undo a prepayment ("Visszavonás"): the loan goes back to how it was, the money back to its pocket.
     */
    public function undo(User $user, LoanEvent $event): void
    {
        if ($event->type !== LoanEventType::Prepayment || $event->principal_before === null || $event->installment_before === null) {
            throw ValidationException::withMessages(['amount' => __('This prepayment cannot be undone.')]);
        }

        DB::transaction(function () use ($user, $event): void {
            $loan = $user->loans()->findOrFail($event->loan_id);
            $loan->update([
                'principal_balance' => $event->principal_before,
                'installment' => $event->installment_before,
                'remaining_months' => $event->remaining_months_before,
            ]);
            $loan->budgetLines()->update(['amount' => $loan->monthlyPayment()]);

            $movement = $event->pocket_movement_id !== null ? $user->pocketMovements()->find($event->pocket_movement_id) : null;

            if ($movement !== null) {
                $user->pockets()->withTrashed()->whereKey($movement->pocket_id)->decrement('balance', $movement->amount);
                $event->update(['pocket_movement_id' => null]);
                $movement->delete();
            }

            $event->delete();
        });
    }
}
