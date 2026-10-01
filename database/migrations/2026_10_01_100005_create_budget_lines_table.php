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
        Schema::create('budget_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount')->default(0);
            $table->unsignedBigInteger('amount_avg')->nullable();
            $table->unsignedBigInteger('amount_max')->nullable();
            $table->string('calc_mode', 10)->default('fixed');
            $table->unsignedTinyInteger('due_day')->nullable();
            $table->foreignId('pocket_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('orig_amount')->nullable();
            $table->string('orig_currency', 3)->nullable();
            $table->date('active_from')->nullable();
            $table->date('active_to')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
    }
};
