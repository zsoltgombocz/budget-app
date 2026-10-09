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
            $table->boolean('version_reminder_enabled')->default(true)->after('due_reminder_enabled');
            // The last app version the user was told about, so each release is announced once.
            $table->string('notified_version', 20)->nullable()->after('version_reminder_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('budget_settings', function (Blueprint $table): void {
            $table->dropColumn(['version_reminder_enabled', 'notified_version']);
        });
    }
};
