<?php

use App\Support\Icons;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (Icons::FROM_HEROICONS as $heroicon => $symbol) {
            DB::table('categories')->where('icon', $heroicon)->update(['icon' => $symbol]);
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
