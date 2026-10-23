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
        Schema::table('period_closes', function (Blueprint $table): void {
            // The one decision at closing: what happened to the leftover (or the deficit).
            $table->string('decision', 20)->nullable()->after('leftover');
            $table->unsignedBigInteger('to_goal')->default(0)->after('decision');
            $table->foreignId('goal_id')->nullable()->after('to_goal')->constrained('pockets')->nullOnDelete();
            $table->bigInteger('carried_over')->default(0)->after('goal_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('period_closes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('goal_id');
            $table->dropColumn(['decision', 'to_goal', 'carried_over']);
        });
    }
};
