<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('budget_settings', function (Blueprint $table): void {
            // The setup wizard's step and answers, so a reload resumes where the user left off.
            $table->json('onboarding_draft')->nullable()->after('onboarded_at');
            // When the "how it works" intro was dismissed; null shows it once after onboarding.
            $table->timestamp('intro_seen_at')->nullable()->after('onboarding_draft');
        });

        // Users who finished onboarding before the intro existed already know the app.
        DB::table('budget_settings')->whereNotNull('onboarded_at')->update(['intro_seen_at' => DB::raw('onboarded_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('budget_settings', function (Blueprint $table): void {
            $table->dropColumn(['onboarding_draft', 'intro_seen_at']);
        });
    }
};
