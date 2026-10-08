<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic capture was only ever deployed to the dev stack and has been removed. This clears
 * what it left behind there; on a database that never had it, nothing happens.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('transactions')->where('source', 'auto')->update(['source' => 'manual']);

        Schema::dropIfExists('payment_captures');
        Schema::dropIfExists('merchant_rules');
        Schema::dropIfExists('capture_tokens');

        if (Schema::hasColumn('budget_settings', 'capture_category_id')) {
            Schema::table('budget_settings', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('capture_category_id');
            });
        }

        $columns = array_values(array_filter(['merchant', 'orig_amount', 'orig_currency'], fn (string $column): bool => Schema::hasColumn('transactions', $column)));

        if ($columns !== []) {
            Schema::table('transactions', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }

        DB::table('migrations')->where('migration', 'like', '2026_10_08_09264%')->delete();
    }
};
