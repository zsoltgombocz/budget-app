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
        Schema::table('period_line_statuses', function (Blueprint $table): void {
            // "Félretettem" on a savings line tied to a goal adds to the goal; undoing it takes this back.
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
