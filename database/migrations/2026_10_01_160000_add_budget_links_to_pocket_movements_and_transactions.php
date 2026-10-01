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
        Schema::table('transactions', function (Blueprint $table): void {
            // Spending paid from a pocket does not use up the period's budget.
            $table->foreignId('pocket_id')->nullable()->after('account_id')->constrained()->nullOnDelete();
        });

        Schema::table('pocket_movements', function (Blueprint $table): void {
            // A withdrawal that tops up the period's budget (counts as extra income).
            $table->boolean('to_budget')->default(false)->after('type');
            $table->foreignId('transaction_id')->nullable()->after('period_id')->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pocket_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('transaction_id');
            $table->dropColumn('to_budget');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pocket_id');
        });
    }
};
