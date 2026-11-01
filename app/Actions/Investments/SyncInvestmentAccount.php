<?php

namespace App\Actions\Investments;

use App\Enums\InvestmentProvider;
use App\Models\Account;
use App\Models\AccountValue;
use App\Services\Investments\IbkrFlex;
use App\Services\Investments\IbkrFlexFailed;
use Illuminate\Support\Facades\DB;

/**
 * Reads an investment account from its broker: every day of the report becomes a point on the
 * trend, the latest is the account's value. A failure is kept on the account for its card.
 */
final readonly class SyncInvestmentAccount
{
    public function __construct(private IbkrFlex $ibkr) {}

    public function handle(Account $account): bool
    {
        if ($account->provider !== InvestmentProvider::Ibkr) {
            return false;
        }

        try {
            $statement = $this->ibkr->fetch($account->credentials['token'] ?? '', $account->credentials['query_id'] ?? '');
        } catch (IbkrFlexFailed $exception) {
            $account->update(['sync_error' => mb_substr($exception->getMessage(), 0, 255)]);

            return false;
        }

        DB::transaction(function () use ($account, $statement): void {
            $now = now();

            AccountValue::query()->withoutGlobalScopes()->upsert(
                array_map(fn (string $date, int $value): array => [
                    'user_id' => $account->user_id,
                    'account_id' => $account->id,
                    'date' => $date,
                    'value' => $value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], array_keys($statement->values), $statement->values),
                ['account_id', 'date'],
                ['value', 'updated_at'],
            );

            $account->update([
                'currency' => $statement->currency,
                'value' => $statement->values[array_key_last($statement->values)],
                'valued_on' => array_key_last($statement->values),
                'synced_at' => $now,
                'sync_error' => null,
            ]);
        });

        return true;
    }
}
