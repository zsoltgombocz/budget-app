<?php

namespace App\Jobs;

use App\Actions\Investments\SyncInvestmentAccount;
use App\Models\Account;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Reads one broker-connected account in the background (a report takes a few seconds to make);
 * while it runs, the account card says it is refreshing.
 */
class SyncInvestment implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $accountId) {}

    public static function pendingKey(int $accountId): string
    {
        return 'investment-sync:'.$accountId;
    }

    public static function start(Account $account): void
    {
        Cache::put(self::pendingKey($account->id), true, now()->addMinutes(3));
        dispatch(new self($account->id));
    }

    public function handle(SyncInvestmentAccount $sync): void
    {
        try {
            $account = Account::query()->withoutGlobalScopes()->find($this->accountId);

            if ($account instanceof Account) {
                $sync->handle($account);
            }
        } finally {
            Cache::forget(self::pendingKey($this->accountId));
        }
    }
}
