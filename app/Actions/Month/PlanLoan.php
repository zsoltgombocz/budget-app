<?php

namespace App\Actions\Month;

use App\Enums\LineType;
use App\Enums\PrepayMode;
use App\Models\BudgetLine;
use App\Models\Loan;
use App\Models\Pocket;
use App\Models\User;
use App\Services\PeriodService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The loan planner (2.0, S23, Plusz): a loan installment line gets its loan's details, and the user
 * can set aside a monthly amount for prepaying it ("Előtörlesztésre félretétel"), an ordinary
 * savings line that fills the loan's pocket on "Félretettem". Nothing is prepaid by itself.
 */
final readonly class PlanLoan
{
    public function __construct(private SaveFixedLine $saveFixedLine, private PeriodService $periods) {}

    /**
     * Add or update the loan behind an installment line. The installment is the line's amount.
     *
     * @param  array{principal: int, thm: float, remaining_months: int, prepay_mode: PrepayMode}  $data
     */
    public function details(User $user, BudgetLine $line, array $data): Loan
    {
        $line->loadMissing('category');
        $errors = [];

        if ($line->category?->type !== LineType::Loan) {
            $errors['principal'] = __('Choose a loan installment.');
        }

        if ($data['principal'] < 1) {
            $errors['principal'] = __('Enter an amount.');
        }

        if ($data['thm'] < 0 || $data['thm'] > 100) {
            $errors['thm'] = __('Enter a rate between 0 and 100%.');
        }

        if ($data['remaining_months'] < 1 || $data['remaining_months'] > 600) {
            $errors['remaining_months'] = __('Enter how many months are left.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($user, $line, $data): Loan {
            $loan = $line->loan_id !== null ? $user->loans()->find($line->loan_id) : null;
            $values = [
                'principal_balance' => $data['principal'],
                'thm' => $data['thm'],
                'remaining_months' => $data['remaining_months'],
                'prepay_mode' => $data['prepay_mode'],
            ];

            if ($loan instanceof Loan) {
                $loan->update([...$values, 'installment' => max(0, $line->amount - $loan->insurance)]);
            } else {
                $loan = $user->loans()->create([...$values, 'name' => $line->category->name ?? '', 'installment' => $line->amount, 'insurance' => 0]);
                $line->update(['loan_id' => $loan->id]);
            }

            return $loan;
        });
    }

    /**
     * "Így szeretném": set aside $monthly a month for the loan, prepaid in $step amounts.
     * Zero stops the saving from this month on; what is already set aside stays in the pocket.
     */
    public function prepay(User $user, Loan $loan, int $monthly, int $step, PrepayMode $mode): ?Pocket
    {
        if ($monthly < 0 || ($monthly > 0 && $step < 1)) {
            throw ValidationException::withMessages(['step' => __('Enter an amount.')]);
        }

        return DB::transaction(function () use ($user, $loan, $monthly, $step, $mode): ?Pocket {
            $loan->update(['prepay_mode' => $mode]);
            $pocket = $loan->pockets()->first();
            $line = $pocket instanceof Pocket ? $user->budgetLines()->with('category')->where('pocket_id', $pocket->id)->whereNull('active_to')->first() : null;

            if ($monthly === 0) {
                // Ends before this month, so the month gets the amount back at once (as archiving a goal).
                $line?->update(['active_to' => $this->periods->current($user)->starts_on->subDay()->toDateString()]);

                return $pocket;
            }

            if (! $pocket instanceof Pocket) {
                $pocket = $user->pockets()->create([
                    'name' => __('Prepayment · :loan', ['loan' => $loan->name]),
                    'loan_id' => $loan->id,
                    'balance' => 0,
                    'sort' => $user->pockets()->withTrashed()->count() + 1,
                ]);
            }

            $pocket->update(['prepay_step' => $step]);

            $this->saveFixedLine->save($user, [
                'name' => $line->category->name ?? __('Prepayment · :loan', ['loan' => $loan->name]),
                'type' => LineType::Sinking,
                'amount' => $monthly,
                'due_day' => $line?->due_day,
                'manual_pay' => true,
                'goal_id' => $pocket->id,
            ], $line);

            return $pocket;
        });
    }
}
