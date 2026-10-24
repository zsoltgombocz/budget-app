<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plans: Alap and Plusz. A plan set by an admin (later a subscription) is stored on the user with
     * an optional end; the one 14-day Plusz offer has its own end. The trial is the first 30 days.
     * Every change is logged in plan_changes. The columns may already exist on dev from the 2.0 build.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'plan')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('plan', 20)->nullable()->after('last_seen_at');
                $table->date('plan_until')->nullable()->after('plan');
                $table->timestamp('plus_offer_until')->nullable()->after('plan_until');
            });
        }

        if (! Schema::hasTable('plan_changes')) {
            Schema::create('plan_changes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('admin_id')->nullable()->constrained()->nullOnDelete();
                $table->string('plan', 20)->nullable();
                $table->date('plan_until')->nullable();
                $table->string('note', 255)->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_changes');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['plan', 'plan_until', 'plus_offer_until']);
        });
    }
};
