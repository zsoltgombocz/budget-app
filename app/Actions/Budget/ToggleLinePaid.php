<?php

namespace App\Actions\Budget;

use App\Enums\PocketMovementType;
use App\Models\BudgetLine;
use App\Models\Period;
use App\Models\PeriodLineStatus;
use App\Models\PocketMovement;
use App\Models\User;
use App\Services\PeriodService;
use Illuminate\Support\Facades\DB;

final readonly class ToggleLinePaid
{
    public function __construct(private PeriodService $periods) {}

    /**
     * Tick a fixed item off for the period, or untick it. A monthly saving into a pocket goes into
     * the pocket when ticked (Plusz) and comes out again when unticked; closing then skips it.
     */
    public function handle(User $user, Period $period, int $budgetLineId): bool
    {
        $line = $user->budgetLines()->findOrFail($budgetLineId);

        return DB::transaction(function () use ($user, $period, $line): bool {
            $status = $period->lineStatuses()->where('budget_line_id', $line->id)->first();

            if ($status !== null && $status->paid_on !== null) {
                $this->takeBack($status);
                $status->update(['paid_on' => null, 'pocket_movement_id' => null]);

                return false;
            }

            $paidOn = $this->periods->today($user->settings())->toDateString();

            if ($status !== null) {
                // Done means it was paid after all: it is no longer skipped.
                $status->update(['paid_on' => $paidOn, 'skipped_on' => null]);
            } else {
                $status = new PeriodLineStatus(['budget_line_id' => $line->id, 'paid_on' => $paidOn]);
                $status->user_id = $user->id;
                $period->lineStatuses()->save($status);
            }

            $status->update(['pocket_movement_id' => $this->deposit($user, $period, $line, $status, $paidOn)]);

            return true;
        });
    }

    private function deposit(User $user, Period $period, BudgetLine $line, PeriodLineStatus $status, string $paidOn): ?int
    {
        $pocket = $line->pocket_id !== null ? $user->pockets()->find($line->pocket_id) : null;
        $amount = $status->amount_actual ?? $line->amount;

        if ($pocket === null || ! $period->isOpen() || ! $user->hasPlusz() || $amount <= 0) {
            return null;
        }

        $movement = $user->pocketMovements()->create([
            'pocket_id' => $pocket->id,
            'period_id' => $period->id,
            'amount' => $amount,
            'type' => PocketMovementType::Deposit,
            'occurred_on' => $paidOn,
            'note' => __('Monthly saving'),
        ]);
        $pocket->increment('balance', $amount);

        return $movement->id;
    }

    private function takeBack(PeriodLineStatus $status): void
    {
        $movement = $status->pocket_movement_id !== null ? PocketMovement::query()->find($status->pocket_movement_id) : null;

        if ($movement === null) {
            return;
        }

        $movement->pocket()->first()?->decrement('balance', $movement->amount);
        $movement->delete();
    }
}
