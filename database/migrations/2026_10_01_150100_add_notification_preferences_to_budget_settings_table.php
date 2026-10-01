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
            $table->boolean('due_reminder_enabled')->default(true)->after('reminder_enabled');
            $table->timestamp('notifications_onboarded_at')->nullable()->after('onboarded_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('budget_settings', function (Blueprint $table): void {
            $table->dropColumn(['due_reminder_enabled', 'notifications_onboarded_at']);
        });
    }
};
