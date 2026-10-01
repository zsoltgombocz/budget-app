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
        Schema::create('budget_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('period_mode', 20)->default('calendar');
            $table->unsignedTinyInteger('payday_day')->nullable();
            $table->unsignedBigInteger('income')->default(0);
            $table->string('currency', 3)->default('HUF');
            $table->string('locale', 5)->default('hu');
            $table->string('timezone')->default('Europe/Budapest');
            $table->time('reminder_time')->default('20:30');
            $table->boolean('reminder_enabled')->default(true);
            $table->unsignedTinyInteger('reserve_pct')->default(100);
            $table->foreignId('surplus_pocket_id')->nullable()->constrained('pockets')->nullOnDelete();
            $table->foreignId('surplus_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->timestamp('onboarded_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_settings');
    }
};
