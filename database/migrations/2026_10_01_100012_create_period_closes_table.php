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
        Schema::create('period_closes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('period_id')->unique()->constrained()->cascadeOnDelete();
            $table->bigInteger('planned_total');
            $table->bigInteger('actual_total');
            $table->bigInteger('leftover');
            $table->unsignedBigInteger('to_reserve')->default(0);
            $table->unsignedBigInteger('to_invest')->default(0);
            $table->unsignedBigInteger('from_reserve')->default(0);
            $table->json('breakdown');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('period_closes');
    }
};
