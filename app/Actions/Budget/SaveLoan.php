<?php

namespace App\Actions\Budget;

use App\Enums\CalcMode;
use App\Enums\LineType;
use App\Enums\PrepayMode;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SaveLoan
{
    /**
     * Create or update a loan and keep its plan line at installment + insurance. A new loan
     * gets its "Loan repayments" line right away, so the installment is in the plan without
     * the user having to add it (and is never recorded by hand). $dueDay is the day of the
     * month the installment is due (null: not set).
     *
     * @param  array{name: string, lender: string|null, principal_balance: int, installment: int, insurance: int, thm: float|null, remaining_months: int|null, prepay_mode: PrepayMode}  $data
     */
    public function handle(User $user, array $data, ?Loan $loan = null, ?int $dueDay = null): Loan
    {
        return DB::transaction(function () use ($user, $data, $loan, $dueDay): Loan {
            $previousName = $loan?->name;

            if ($loan instanceof Loan) {
                $loan->update($data);
            } else {
                $loan = $user->loans()->create($data);
            }

            if (! $loan->budgetLines()->exists()) {
                self::addPlanLine($user, $loan);
            }

            // The installment's due day lives on the plan line (it drives the due-day reminder).
            $loan->budgetLines()->update(['amount' => $loan->monthlyPayment(), 'due_day' => $dueDay]);

            if ($previousName !== null && $previousName !== $loan->name) {
                $user->categories()
                    ->whereIn('id', $loan->budgetLines()->select('category_id'))
                    ->where('name', $previousName)
                    ->update(['name' => $loan->name]);
            }

            return $loan;
        });
    }

    /**
     * The loan's line in the plan's repayment section, named after the loan.
     */
    public static function addPlanLine(User $user, Loan $loan): void
    {
        $category = $user->categories()->create([
            'name' => $loan->name,
            'type' => LineType::Loan,
            'icon' => 'account_balance',
            'color' => 'rose',
            'sort' => $user->categories()->count() + 1,
            'is_quick_entry' => false,
        ]);

        $user->budgetLines()->create([
            'category_id' => $category->id,
            'amount' => $loan->monthlyPayment(),
            'calc_mode' => CalcMode::Fixed,
            'loan_id' => $loan->id,
        ]);
    }
}
