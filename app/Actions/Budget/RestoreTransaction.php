<?php

namespace App\Actions\Budget;

use App\Enums\PocketMovementType;
use App\Enums\TransactionSource;
use App\Models\Category;
use App\Models\Pocket;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Undo DeleteTransaction: record the spending again with the same data, its client UUID
 * included. A pocket-paid spending is paid from the pocket again (the deletion put the money
 * back), so the pocket's balance ends up where it was before the deletion.
 */
final readonly class RestoreTransaction
{
    public function __construct(private PeriodService $periods) {}

    /**
     * @param  array{category_id: int, amount: int, occurred_on: string, note: string|null, source: string, client_uuid: string|null, account_id: int|null, external_ref: string|null, pocket_id: int|null, pocket_note: string|null}  $snapshot
     */
    public function handle(User $user, array $snapshot): Transaction
    {
        if ($snapshot['client_uuid'] !== null) {
            $existing = $user->transactions()->where('client_uuid', $snapshot['client_uuid'])->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $category = $user->categories()->withTrashed()->find($snapshot['category_id']);

        if (! $category instanceof Category) {
            throw ValidationException::withMessages(['category' => __('Choose a category.')]);
        }

        $date = CarbonImmutable::parse($snapshot['occurred_on']);
        $period = $this->periods->forDate($user, $date);

        if (! $period->isOpen()) {
            throw ValidationException::withMessages(['date' => __('That period is already closed.')]);
        }

        $pocket = $snapshot['pocket_id'] === null ? null : $user->pockets()->withTrashed()->find($snapshot['pocket_id']);

        if ($pocket instanceof Pocket && $pocket->balance < $snapshot['amount']) {
            throw ValidationException::withMessages(['amount' => __('The pocket does not have enough money.')]);
        }

        return DB::transaction(function () use ($user, $snapshot, $category, $date, $period, $pocket): Transaction {
            $transaction = $user->transactions()->create([
                'period_id' => $period->id,
                'category_id' => $category->id,
                'account_id' => $snapshot['account_id'] !== null && $user->accounts()->whereKey($snapshot['account_id'])->exists() ? $snapshot['account_id'] : null,
                'pocket_id' => $pocket?->id,
                'amount' => $snapshot['amount'],
                'occurred_on' => $date->toDateString(),
                'note' => $snapshot['note'],
                'source' => TransactionSource::tryFrom($snapshot['source']) ?? TransactionSource::Manual,
                'client_uuid' => $snapshot['client_uuid'],
                'external_ref' => $snapshot['external_ref'],
            ]);

            if ($pocket instanceof Pocket) {
                $user->pocketMovements()->create([
                    'pocket_id' => $pocket->id,
                    'period_id' => $period->id,
                    'transaction_id' => $transaction->id,
                    'amount' => -$snapshot['amount'],
                    'type' => PocketMovementType::Withdraw,
                    'occurred_on' => $date->toDateString(),
                    'note' => $snapshot['pocket_note'],
                ]);

                $pocket->decrement('balance', $snapshot['amount']);
            }

            $user->dayMarks()->whereDate('date', $date->toDateString())->delete();

            return $transaction;
        });
    }
}
