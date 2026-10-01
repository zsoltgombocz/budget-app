<?php

namespace App\Actions\Budget;

use App\Enums\PocketMovementType;
use App\Models\Pocket;
use App\Models\PocketMovement;
use App\Models\User;
use App\Services\PeriodService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class MovePocketMoney
{
    public function __construct(private PeriodService $periods) {}

    /**
     * Manual deposit (positive) or withdrawal (negative). A withdrawal with $toBudget
     * tops up the current period's budget, as if it were extra income.
     */
    public function handle(User $user, Pocket $pocket, int $amount, ?string $note = null, bool $toBudget = false): PocketMovement
    {
        if ($amount === 0) {
            throw ValidationException::withMessages(['amount' => __('The amount must be greater than zero.')]);
        }

        if ($amount < 0 && $pocket->balance < -$amount) {
            throw ValidationException::withMessages(['amount' => __('The pocket does not have enough money.')]);
        }

        return DB::transaction(function () use ($user, $pocket, $amount, $note, $toBudget): PocketMovement {
            $today = $this->periods->today($user->settings());

            $movement = $user->pocketMovements()->create([
                'pocket_id' => $pocket->id,
                'period_id' => $this->periods->forDate($user, $today)->id,
                'amount' => $amount,
                'type' => $amount > 0 ? PocketMovementType::Deposit : PocketMovementType::Withdraw,
                'to_budget' => $amount < 0 && $toBudget,
                'occurred_on' => $today->toDateString(),
                'note' => filled($note) ? $note : null,
            ]);

            $pocket->increment('balance', $amount);

            return $movement;
        });
    }
}
