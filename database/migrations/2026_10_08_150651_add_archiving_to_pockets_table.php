<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deleting a pocket archives it (soft delete), so its movements and the spending it paid
     * for stay in past periods instead of being cascaded away.
     */
    public function up(): void
    {
        Schema::table('pockets', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('pockets', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
