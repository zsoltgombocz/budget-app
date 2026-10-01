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
        Schema::create('loans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('lender')->nullable();
            $table->unsignedBigInteger('principal_balance');
            $table->unsignedBigInteger('installment');
            $table->unsignedBigInteger('insurance')->default(0);
            $table->decimal('thm', 6, 3)->nullable();
            $table->unsignedSmallInteger('remaining_months')->nullable();
            $table->string('prepay_mode', 30)->default('reduce_installment');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
