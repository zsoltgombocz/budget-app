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
     * Manual deposit (positive) or withdrawal (negative).
     */
    public function handle(User $user, Pocket $pocket, int $amount, ?string $note = null): PocketMovement
    {
        if ($amount === 0) {
            throw ValidationException::withMessages(['amount' => __('The amount must be greater than zero.')]);
        }

        return DB::transaction(function () use ($user, $pocket, $amount, $note): PocketMovement {
            $today = $this->periods->today($user->settings());

            $movement = $user->pocketMovements()->create([
                'pocket_id' => $pocket->id,
                'period_id' => $this->periods->forDate($user, $today)->id,
                'amount' => $amount,
                'type' => $amount > 0 ? PocketMovementType::Deposit : PocketMovementType::Withdraw,
                'occurred_on' => $today->toDateString(),
                'note' => filled($note) ? $note : null,
            ]);

            $pocket->increment('balance', $amount);

            return $movement;
        });
    }
}
