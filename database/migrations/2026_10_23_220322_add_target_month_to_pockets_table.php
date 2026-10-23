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
        Schema::table('pockets', function (Blueprint $table): void {
            // A goal's deadline ("Meddig?"), the first day of its month; null: no deadline.
            $table->date('target_month')->nullable()->after('target_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pockets', function (Blueprint $table): void {
            $table->dropColumn('target_month');
        });
    }
};
