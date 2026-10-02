<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_admin')->default(false)->after('email_verified_at');
            $table->timestamp('invited_at')->nullable()->after('is_admin');
            $table->timestamp('disabled_at')->nullable()->after('invited_at');
            $table->timestamp('last_seen_at')->nullable()->after('disabled_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['is_admin', 'invited_at', 'disabled_at', 'last_seen_at']);
        });
    }
};
