<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The dev database was converted by the 2.0 build, which already added it.
        if (Schema::hasColumn('period_line_statuses', 'skipped_on')) {
            return;
        }

        Schema::table('period_line_statuses', function (Blueprint $table): void {
            $table->date('skipped_on')->nullable()->after('paid_on');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('period_line_statuses', function (Blueprint $table): void {
            $table->dropColumn('skipped_on');
        });
    }
};
