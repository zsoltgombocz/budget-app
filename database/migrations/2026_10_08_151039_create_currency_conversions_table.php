<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Base currency changes, kept so that switching back restores the original amounts.
     *
     * Each conversion keeps the rates it used and, per converted money cell, the value before
     * and after. Undoing it puts back the "before" value where the cell still holds the
     * "after" value, and converts anything added or changed since with the inverse rate.
     */
    public function up(): void
    {
        Schema::create('currency_conversions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('from_currency', 3);
            $table->string('to_currency', 3);
            // Forints per one unit of each currency ("1" for HUF), as quoted by MNB.
            $table->decimal('from_rate', 20, 8);
            $table->decimal('to_rate', 20, 8);
            $table->date('rate_date');
            $table->timestamps();
        });

        Schema::create('currency_conversion_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('currency_conversion_id')->constrained()->cascadeOnDelete();
            $table->string('table_name', 40);
            $table->unsignedBigInteger('row_id');
            $table->string('column_name', 40);
            // Integers as digits, JSON columns as JSON text.
            $table->longText('before');
            $table->longText('after');
            $table->unique(['currency_conversion_id', 'table_name', 'row_id', 'column_name'], 'currency_conversion_values_cell_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_conversion_values');
        Schema::dropIfExists('currency_conversions');
    }
};
