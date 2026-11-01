<?php

namespace App\Console\Commands;

use App\Actions\Investments\SyncInvestmentAccount;
use App\Enums\InvestmentProvider;
use App\Models\Account;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('investments:sync')]
#[Description('Read the value of every broker-connected investment account (Plusz users only)')]
class SyncInvestments extends Command
{
    public function handle(SyncInvestmentAccount $sync): int
    {
        $accounts = Account::query()->withoutGlobalScopes()->with('user')
            ->where('provider', InvestmentProvider::Ibkr)
            ->get();

        foreach ($accounts as $account) {
            if ($account->user?->hasPlusz() !== true) {
                continue;
            }

            $this->line($account->id.': '.($sync->handle($account) ? 'ok' : (string) $account->sync_error));
        }

        return self::SUCCESS;
    }
}
