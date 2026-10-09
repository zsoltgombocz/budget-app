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
        Schema::create('notification_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The Laravel notification id, to attach the push service's answer to the row.
            $table->uuid('notification_id')->nullable()->index();
            $table->string('type', 40);
            // queued, sent (accepted by the push service), failed, skipped
            $table->string('status', 20);
            $table->string('reason', 255)->nullable();
            $table->unsignedSmallInteger('push_status')->nullable();
            $table->string('push_host', 120)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
