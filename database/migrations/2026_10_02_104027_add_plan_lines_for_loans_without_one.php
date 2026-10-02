<?php

use App\Actions\Budget\SaveLoan;
use App\Models\Loan;
use Illuminate\Database\Migrations\Migration;

/**
 * Loans added on the Pockets screen used to get no plan line, so their installment was
 * missing from the plan. Give each of them its repayment line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Loan::query()->withoutGlobalScopes()->whereDoesntHave('budgetLines')->with('user')->each(function (Loan $loan): void {
            if ($loan->user !== null) {
                SaveLoan::addPlanLine($loan->user, $loan);
            }
        });
    }

    public function down(): void
    {
        //
    }
};
