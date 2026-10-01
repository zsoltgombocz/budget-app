<?php

namespace App\Actions\Budget;

use App\Enums\PrepayMode;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SaveLoan
{
    /**
     * Create or update a loan and keep its plan lines at installment + insurance.
     *
     * @param  array{name: string, lender: string|null, principal_balance: int, installment: int, insurance: int, thm: float|null, remaining_months: int|null, prepay_mode: PrepayMode}  $data
     */
    public function handle(User $user, array $data, ?Loan $loan = null): Loan
    {
        return DB::transaction(function () use ($user, $data, $loan): Loan {
            if ($loan instanceof Loan) {
                $loan->update($data);
            } else {
                $loan = $user->loans()->create($data);
            }

            $loan->budgetLines()->update(['amount' => $loan->monthlyPayment()]);

            return $loan;
        });
    }
}
