<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Milestones and the weekly challenge. The tables may already exist on dev from the 2.0 build.
     */
    public function up(): void
    {
        if (! Schema::hasTable('achievements')) {
            Schema::create('achievements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('key', 30);
                $table->timestamp('achieved_at');
                $table->timestamp('celebrated_at')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'key']);
            });
        }

        if (! Schema::hasTable('challenges')) {
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
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('challenges');
        Schema::dropIfExists('achievements');
    }
};
