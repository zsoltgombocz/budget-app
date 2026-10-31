<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A transfer is money sent to another account and counts as a fixed cost; money going into a
     * pocket is a monthly saving. Transfer items that fed a pocket become monthly savings.
     */
    public function up(): void
    {
        $categoryIds = DB::table('budget_lines')
            ->join('categories', 'categories.id', '=', 'budget_lines.category_id')
            ->where('categories.type', 'transfer')
            ->whereNotNull('budget_lines.pocket_id')
            ->distinct()
            ->pluck('budget_lines.category_id');

        DB::table('categories')->whereIn('id', $categoryIds)->update(['type' => 'sinking']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
