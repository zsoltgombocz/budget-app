<?php

namespace App\Actions\Budget;

use App\Models\BudgetLine;
use Illuminate\Support\Facades\DB;

final class DeletePlanLine
{
    /**
     * Remove the line from the plan. The category is soft deleted so past
     * transactions and closed periods keep their names.
     */
    public function handle(BudgetLine $line): void
    {
        DB::transaction(function () use ($line): void {
            $category = $line->category;
            $line->delete();

            if ($category !== null && ! $category->budgetLines()->exists()) {
                $category->delete();
            }
        });
    }
}
