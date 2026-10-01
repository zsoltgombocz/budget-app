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
            // Leftover meant for an account outside the app: remind until it is marked transferred.
            $table->foreignId('surplus_account_id')->nullable()->after('to_invest')->constrained('accounts')->nullOnDelete();
            $table->timestamp('surplus_transferred_at')->nullable()->after('surplus_account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('period_closes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('surplus_account_id');
            $table->dropColumn('surplus_transferred_at');
        });
    }
};
