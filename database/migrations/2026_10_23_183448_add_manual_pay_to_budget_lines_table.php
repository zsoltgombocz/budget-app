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
        Schema::table('budget_lines', function (Blueprint $table): void {
            // "Kézzel fizetem": on its due day the line gets an "Elintéztem" / "Félretettem" button.
            $table->boolean('manual_pay')->default(false)->after('due_day');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('budget_lines', function (Blueprint $table): void {
            $table->dropColumn('manual_pay');
        });
    }
};
