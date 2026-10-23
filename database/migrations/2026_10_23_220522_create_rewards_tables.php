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
        // Acorns ("makk"): earned by habit (writing in, streaks, challenges, milestones), spent on Morzsa's things. Never for money.
        Schema::create('acorn_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('amount');
            $table->string('reason', 30);
            // What it was for (a day, a milestone, an item); with the reason it makes an award happen only once.
            $table->string('ref', 40);
            $table->timestamps();

            $table->unique(['user_id', 'reason', 'ref']);
        });

        Schema::create('achievements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 30);
            $table->timestamp('achieved_at');
            $table->timestamp('celebrated_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
        });

        Schema::create('challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->string('kind', 30);
            $table->unsignedTinyInteger('target');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'week_start']);
        });

        Schema::create('user_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('item', 30);
            $table->boolean('equipped')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'item']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_items');
        Schema::dropIfExists('challenges');
        Schema::dropIfExists('achievements');
        Schema::dropIfExists('acorn_events');
    }
};
