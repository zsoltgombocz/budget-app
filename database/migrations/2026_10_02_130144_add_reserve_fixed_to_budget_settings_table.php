<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The leftover rule can be a fixed amount instead of a share: when set, at most this much
     * of the month-end leftover goes to the reserve and reserve_pct is not used.
     */
    public function up(): void
    {
        Schema::table('budget_settings', function (Blueprint $table): void {
            $table->unsignedBigInteger('reserve_fixed')->nullable()->after('reserve_pct');
        });
    }

    public function down(): void
    {
        Schema::table('budget_settings', function (Blueprint $table): void {
            $table->dropColumn('reserve_fixed');
        });
    }
};
