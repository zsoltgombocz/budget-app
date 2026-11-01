<?php

namespace App\Actions\Investments;

use App\Enums\AccountType;
use App\Enums\Currency;
use App\Enums\InvestmentProvider;
use App\Models\Account;
use App\Models\User;

/**
 * Adds or changes an investment account. An empty token on an existing IBKR account keeps the
 * saved one, so the key never has to travel back to the browser.
 */
final readonly class SaveInvestmentAccount
{
    /**
     * @param  array{name: string, currency: Currency, provider: InvestmentProvider, token?: string|null, query_id?: string|null}  $data
     */
    public function handle(User $user, array $data, ?Account $account = null): Account
    {
        $account ??= new Account(['type' => AccountType::Investment]);
        $account->user_id = $user->id;

        $credentials = null;

        if ($data['provider'] === InvestmentProvider::Ibkr) {
            $token = trim((string) ($data['token'] ?? ''));
            $credentials = [
                'token' => $token !== '' ? $token : ($account->credentials['token'] ?? ''),
                'query_id' => trim((string) ($data['query_id'] ?? '')),
            ];
        }

        $providerChanged = $account->exists && $account->provider !== $data['provider'];

        $account->fill([
            'name' => $data['name'],
            'currency' => $data['currency'],
            'provider' => $data['provider'],
            'credentials' => $credentials,
            'sync_error' => $providerChanged ? null : $account->sync_error,
        ])->save();

        return $account;
    }
}
