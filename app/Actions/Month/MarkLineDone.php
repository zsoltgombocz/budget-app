<?php

namespace App\Actions\Month;

use App\Enums\LineType;
use App\Enums\PocketMovementType;
use App\Models\Period;
use App\Models\PeriodLineStatus;
use App\Models\Pocket;
use App\Models\User;
use App\Services\PeriodService;
use Illuminate\Support\Facades\DB;

/**
 * "Elintéztem" / "Félretettem" on a line paid by hand, and its undo.
 *
 * The month's numbers never change: the line is taken off in advance. A savings line tied to a goal
 * adds its amount to the goal on the tap (and only then); undoing takes exactly that back.
 */
final readonly class MarkLineDone
{
    public function __construct(private PeriodService $periods) {}

    /**
     * @return int|null what the goal got (null when the line has no goal)
     */
    public function mark(User $user, Period $period, int $budgetLineId): ?int
    {
        $line = $user->budgetLines()->with('category')->findOrFail($budgetLineId);
        $today = $this->periods->today($user->settings())->toDateString();

        return DB::transaction(function () use ($user, $period, $line, $today): ?int {
            $status = $this->status($user, $period, $line->id);

            if ($status->paid_on !== null) {
                return null;
            }

            $amount = $status->amount_actual ?? $line->amount;
            $goal = $line->category?->type === LineType::Sinking && $line->pocket_id !== null
                ? $user->pockets()->find($line->pocket_id)
                : null;
            $movementId = null;

            if ($goal instanceof Pocket && $amount > 0) {
                $movementId = $user->pocketMovements()->create([
                    'pocket_id' => $goal->id,
                    'period_id' => $period->id,
                    'amount' => $amount,
                    'type' => PocketMovementType::Deposit,
                    'occurred_on' => $today,
                    'note' => __('Set aside'),
                ])->id;
                $goal->increment('balance', $amount);
            }

            $status->fill(['paid_on' => $today, 'skipped_on' => null, 'pocket_movement_id' => $movementId])->save();

            return $goal instanceof Pocket ? $amount : null;
        });
    }

    public function undo(User $user, Period $period, int $budgetLineId): void
    {
        $line = $user->budgetLines()->findOrFail($budgetLineId);

        DB::transaction(function () use ($user, $period, $line): void {
            $status = $period->lineStatuses()->where('budget_line_id', $line->id)->first();

            if (! $status instanceof PeriodLineStatus || $status->paid_on === null) {
                return;
            }

            $movement = $status->pocket_movement_id !== null ? $user->pocketMovements()->find($status->pocket_movement_id) : null;

            $status->update(['paid_on' => null, 'pocket_movement_id' => null]);

            if ($movement !== null) {
                $user->pockets()->withTrashed()->whereKey($movement->pocket_id)->decrement('balance', $movement->amount);
                $movement->delete();
            }
        });
    }

    private function status(User $user, Period $period, int $budgetLineId): PeriodLineStatus
    {
        $status = $period->lineStatuses()->where('budget_line_id', $budgetLineId)->first();

        if ($status instanceof PeriodLineStatus) {
            return $status;
        }

        $status = new PeriodLineStatus(['period_id' => $period->id, 'budget_line_id' => $budgetLineId]);
        $status->user_id = $user->id;

        return $status;
    }
}
