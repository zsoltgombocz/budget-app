<?php

use App\Enums\PeriodStatus;
use App\Enums\PocketMovementType;
use App\Models\BudgetLine;
use App\Models\Period;
use App\Models\PeriodLineStatus;
use App\Models\Pocket;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Monthly savings already ticked off as transferred in an open month go into their pockets now,
     * as a new tick does; closing then leaves them out.
     */
    public function up(): void
    {
        $statuses = PeriodLineStatus::query()->withoutGlobalScopes()
            ->whereNotNull('paid_on')->whereNull('pocket_movement_id')
            ->whereIn('period_id', Period::query()->withoutGlobalScopes()->where('status', PeriodStatus::Open)->select('id'))
            ->get();

        foreach ($statuses as $status) {
            $line = BudgetLine::query()->withoutGlobalScopes()->find($status->budget_line_id);

            if ($line === null) {
                continue;
            }

            $user = User::query()->find($status->user_id);
            $pocket = $line->pocket_id !== null ? Pocket::query()->withoutGlobalScopes()->find($line->pocket_id) : null;
            $amount = $status->amount_actual ?? $line->amount;

            if ($pocket === null || $user === null || ! $user->hasPlusz() || $amount <= 0) {
                continue;
            }

            DB::transaction(function () use ($status, $pocket, $amount): void {
                $movementId = DB::table('pocket_movements')->insertGetId([
                    'user_id' => $status->user_id,
                    'pocket_id' => $pocket->id,
                    'period_id' => $status->period_id,
                    'amount' => $amount,
                    'type' => PocketMovementType::Deposit->value,
                    'to_budget' => false,
                    'occurred_on' => $status->paid_on?->toDateString(),
                    'note' => 'Havi befizetés',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $pocket->increment('balance', $amount);
                $status->update(['pocket_movement_id' => $movementId]);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
