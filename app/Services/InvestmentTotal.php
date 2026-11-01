<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Models\User;
use App\Services\ExchangeRates\LatestRates;

/**
 * All of a user's investment accounts together, in the base currency at today's official rate.
 */
final readonly class InvestmentTotal
{
    public function __construct(private LatestRates $rates) {}

    /**
     * Null when no account has a value yet (or none can be converted).
     */
    public function forUser(User $user): ?int
    {
        $base = $user->settings()->currency;
        $total = null;

        foreach ($user->accounts()->where('type', AccountType::Investment)->whereNotNull('value')->get() as $account) {
            $value = $this->rates->convert((int) $account->value, $account->currency, $base);

            if ($value !== null) {
                $total = ($total ?? 0) + $value;
            }
        }

        return $total;
    }
}
