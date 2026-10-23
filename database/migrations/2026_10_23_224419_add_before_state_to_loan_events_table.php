<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A prepayment keeps the loan as it was before, and the pocket movement it took, so it can be undone.
     */
    public function up(): void
    {
        Schema::table('loan_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('principal_before')->nullable()->after('amount');
            $table->unsignedBigInteger('installment_before')->nullable()->after('principal_before');
            $table->unsignedSmallInteger('remaining_months_before')->nullable()->after('installment_before');
            $table->foreignId('pocket_movement_id')->nullable()->after('remaining_months_after')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loan_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pocket_movement_id');
            $table->dropColumn(['principal_before', 'installment_before', 'remaining_months_before']);
        });
    }
};
