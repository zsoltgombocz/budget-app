<?php

namespace App\Actions\Budget;

use App\Enums\PocketMovementType;
use App\Models\Pocket;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class PayFromPocket
{
    public function __construct(private RecordTransaction $recordTransaction) {}

    /**
     * Record a spending that the pocket pays for. It shows up in the month list but does
     * not use up the period's budget. Deleting the spending puts the money back.
     */
    public function handle(User $user, Pocket $pocket, int $categoryId, int $amount, ?string $note = null, ?CarbonImmutable $date = null): Transaction
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('The amount must be greater than zero.')]);
        }

        if ($pocket->balance < $amount) {
            throw ValidationException::withMessages(['amount' => __('The pocket does not have enough money.')]);
        }

        return DB::transaction(function () use ($user, $pocket, $categoryId, $amount, $note, $date): Transaction {
            $transaction = $this->recordTransaction->handle($user, $categoryId, $amount, $date, $note ?? $pocket->name, (string) Str::uuid());
            $transaction->update(['pocket_id' => $pocket->id]);

            $user->pocketMovements()->create([
                'pocket_id' => $pocket->id,
                'period_id' => $transaction->period_id,
                'transaction_id' => $transaction->id,
                'amount' => -$amount,
                'type' => PocketMovementType::Withdraw,
                'occurred_on' => $transaction->occurred_on->toDateString(),
                'note' => $note,
            ]);

            $pocket->decrement('balance', $amount);

            return $transaction;
        });
    }
}
