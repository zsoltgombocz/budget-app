<?php

use App\Enums\Currency;
use App\Models\User;
use App\Support\Money;

if (! function_exists('money')) {
    /**
     * Format an amount in the smallest unit, by default in the signed-in user's currency.
     */
    function money(int $amount, ?Currency $currency = null): string
    {
        return Money::of($amount, $currency ?? user_currency())->format();
    }
}

if (! function_exists('money_number')) {
    /**
     * Just the number of an amount, e.g. "12 951" or "24,99" — paired with the currency symbol in the UI.
     * Keeps the currency's decimals, like money(), so the amounts of a summary add up on screen.
     */
    function money_number(int $amount, ?Currency $currency = null): string
    {
        return Money::of($amount, $currency ?? user_currency())->formatNumber(withDecimals: true);
    }
}

if (! function_exists('user_currency')) {
    function user_currency(): Currency
    {
        $user = auth()->user();

        return $user instanceof User ? $user->settings()->currency : Currency::HUF;
    }
}
