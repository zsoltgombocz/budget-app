<?php

namespace App\Actions\Budget;

use App\Enums\PocketMovementType;
use App\Models\PocketMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes back a manual deposit or withdrawal made on the Pockets screen (the toast's Undo):
 * the movement is deleted and the pocket's balance goes back by its amount, so the month's
 * leftover is as it was. Movements of a closing, a prepayment or a spending paid from the
 * pocket are not undone here, nor anything in a closed period.
 */
final class UndoPocketMovement
{
    public function handle(User $user, int $movementId): PocketMovement
    {
        $movement = $user->pocketMovements()->with(['pocket', 'period'])->find($movementId);

        if (! $movement instanceof PocketMovement
            || $movement->transaction_id !== null
            || ! in_array($movement->type, [PocketMovementType::Deposit, PocketMovementType::Withdraw], true)
            || $movement->pocket === null
            || ($movement->period !== null && ! $movement->period->isOpen())) {
            throw ValidationException::withMessages(['movement' => __('This money movement can no longer be undone.')]);
        }

        if ($movement->amount > 0 && $movement->pocket->balance < $movement->amount) {
            throw ValidationException::withMessages(['movement' => __('The pocket does not have enough money.')]);
        }

        DB::transaction(function () use ($movement): void {
            $movement->pocket->decrement('balance', $movement->amount);
            $movement->delete();
        });

        return $movement;
    }
}
