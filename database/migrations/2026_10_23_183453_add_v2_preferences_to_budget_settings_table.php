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
        Schema::table('budget_settings', function (Blueprint $table): void {
            $table->boolean('weekly_summary_enabled')->default(true)->after('due_reminder_enabled');
            $table->boolean('watch_push_enabled')->default(false)->after('weekly_summary_enabled');
            // Morzsa: full, quiet (fewer bubbles) or off (the Morzsa tab becomes "Haladás").
            $table->string('companion_mode', 10)->default('full')->after('watch_push_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('budget_settings', function (Blueprint $table): void {
            $table->dropColumn(['weekly_summary_enabled', 'watch_push_enabled', 'companion_mode']);
        });
    }
};
