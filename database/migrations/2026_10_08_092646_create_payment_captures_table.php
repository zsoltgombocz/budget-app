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
        // Every payment the phone sent in, with what became of it. Only the fields needed
        // for the payment are kept; the raw notification text is pruned after a few days.
        Schema::create('payment_captures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            // The manual entry it may duplicate, or the spending a refund belongs to.
            $table->foreignId('related_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->string('external_id', 100)->nullable();
            $table->string('status', 20);
            $table->string('reason', 30)->nullable();
            $table->string('kind', 20);
            $table->bigInteger('amount')->nullable();
            $table->string('currency', 3)->nullable();
            $table->bigInteger('base_amount')->nullable();
            $table->string('merchant', 120)->nullable();
            $table->string('platform', 20)->nullable();
            $table->timestamp('occurred_at');
            $table->text('raw_text')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'external_id']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_captures');
    }
};
