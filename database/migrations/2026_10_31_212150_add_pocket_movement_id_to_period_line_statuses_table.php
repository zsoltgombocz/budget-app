<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A monthly saving ticked off as transferred goes into its pocket right away; the deposit is
     * kept on the tick so unticking takes back exactly that and closing does not move it again.
     */
    public function up(): void
    {
        if (Schema::hasColumn('period_line_statuses', 'pocket_movement_id')) {
            return;
        }

        Schema::table('period_line_statuses', function (Blueprint $table): void {
            $table->foreignId('pocket_movement_id')->nullable()->after('skipped_on')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('period_line_statuses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pocket_movement_id');
        });
    }
};
