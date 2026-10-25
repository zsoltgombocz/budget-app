<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A loan's plan line is named after the loan ("Hitel" on the plan and "Személyi kölcsön" in
     * My money were the same thing under two names).
     */
    public function up(): void
    {
        $lines = DB::table('budget_lines')
            ->join('loans', 'loans.id', '=', 'budget_lines.loan_id')
            ->whereNotNull('budget_lines.loan_id')
            ->get(['budget_lines.category_id', 'loans.name']);

        foreach ($lines as $line) {
            DB::table('categories')->where('id', $line->category_id)->update(['name' => $line->name]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
