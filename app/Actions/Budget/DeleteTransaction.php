<?php

namespace App\Actions\Budget;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Delete a spending of an open period. A pocket-paid spending puts its money back into the
 * pocket (see Transaction::booted) and its withdrawal goes with it.
 *
 * Returns everything RestoreTransaction needs to bring the spending back as it was.
 */
final readonly class DeleteTransaction
{
    /**
     * @return array{category_id: int, amount: int, occurred_on: string, note: string|null, source: string, client_uuid: string|null, account_id: int|null, external_ref: string|null, pocket_id: int|null, pocket_note: string|null}
     */
    public function handle(User $user, Transaction $transaction): array
    {
        if ($transaction->user_id !== $user->id) {
            throw ValidationException::withMessages(['transaction' => __('This spending was not found.')]);
        }

        if (! $transaction->period()->firstOrFail()->isOpen()) {
            throw ValidationException::withMessages(['date' => __('That period is already closed.')]);
        }

        $snapshot = [
            'category_id' => $transaction->category_id,
            'amount' => $transaction->amount,
            'occurred_on' => $transaction->occurred_on->toDateString(),
            'note' => $transaction->note,
            'source' => $transaction->source->value,
            'client_uuid' => $transaction->client_uuid,
            'account_id' => $transaction->account_id,
            'external_ref' => $transaction->external_ref,
            'pocket_id' => $transaction->pocket_id,
            'pocket_note' => $transaction->pocket_id === null ? null : $user->pocketMovements()->where('transaction_id', $transaction->id)->first()?->note,
        ];

        $transaction->delete();

        return $snapshot;
    }
}
