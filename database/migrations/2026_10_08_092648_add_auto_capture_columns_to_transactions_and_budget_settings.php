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
            $table->string('merchant', 120)->nullable()->after('note');
            // A foreign-currency payment keeps what was paid; `amount` stays in the base currency.
            $table->bigInteger('orig_amount')->nullable()->after('merchant');
            $table->string('orig_currency', 3)->nullable()->after('orig_amount');
        });

        Schema::table('budget_settings', function (Blueprint $table): void {
            // Where automatically captured payments of unknown merchants go.
            $table->foreignId('capture_category_id')->nullable()->constrained('categories')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('budget_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('capture_category_id');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn(['merchant', 'orig_amount', 'orig_currency']);
        });
    }
};
