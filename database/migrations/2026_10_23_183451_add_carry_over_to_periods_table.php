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
        Schema::table('periods', function (Blueprint $table): void {
            // Money the previous month's closing carried over (negative when a deficit was deducted).
            $table->bigInteger('carried_over')->default(0)->after('income_actual');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('periods', function (Blueprint $table): void {
            $table->dropColumn('carried_over');
        });
    }
};
