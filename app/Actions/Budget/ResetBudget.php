<?php

namespace App\Actions\Budget;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ResetBudget
{
    /**
     * Delete every budget record of the user and start onboarding again.
     * The login, passkeys, push subscriptions and automatic capture keys are kept.
     */
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->budgetSetting()->delete();
            $user->periodCloses()->delete();
            $user->paymentCaptures()->delete();
            $user->merchantRules()->delete();
            $user->transactions()->delete();
            DB::table('period_line_statuses')->where('user_id', $user->id)->delete();
            $user->pocketMovements()->delete();
            $user->loanEvents()->delete();
            $user->periods()->delete();
            $user->budgetLines()->delete();
            $user->categories()->withTrashed()->forceDelete();
            $user->pockets()->delete();
            $user->loans()->delete();
            $user->accounts()->delete();
            $user->dayMarks()->delete();

            $user->unsetRelation('budgetSetting');
        });
    }
}
