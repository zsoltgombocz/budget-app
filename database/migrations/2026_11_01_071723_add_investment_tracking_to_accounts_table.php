<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Investment accounts show their value (in their own currency): typed in by hand, or read
     * daily from the broker with a read-only key kept encrypted.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('provider')->default('manual')->after('currency');
            $table->text('credentials')->nullable()->after('provider');
            $table->bigInteger('value')->nullable()->after('credentials');
            $table->date('valued_on')->nullable()->after('value');
            $table->timestamp('synced_at')->nullable()->after('valued_on');
            $table->string('sync_error')->nullable()->after('synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['provider', 'credentials', 'value', 'valued_on', 'synced_at', 'sync_error']);
        });
    }
};
