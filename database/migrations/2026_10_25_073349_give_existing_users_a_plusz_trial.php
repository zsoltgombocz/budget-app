<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Alap became free and Plusz paid: everyone who was already here gets the 14-day Plusz trial
     * from the release, instead of falling to Alap on the spot.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNull('plus_offer_until')
            ->where('created_at', '<', now()->subDays(14))
            ->update(['plus_offer_until' => now()->addDays(14)]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
