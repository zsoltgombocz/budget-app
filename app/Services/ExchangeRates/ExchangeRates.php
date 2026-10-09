<?php

namespace App\Services\ExchangeRates;

use App\Enums\Currency;

/**
 * One day's official rates: how many forints one unit of each currency is worth,
 * as exact decimal strings (e.g. "366.45"; JPY's per-100 quote is already divided).
 */
final readonly class ExchangeRates
{
    /**
     * @param  string  $date  the day the rates were published for (Y-m-d)
     * @param  array<string, string>  $hufPerUnit  currency code => forints per one unit
     */
    public function __construct(
        public string $date,
        public array $hufPerUnit,
    ) {}

    /**
     * Forints per one unit of the currency; "1" for the forint itself.
     *
     * @throws ExchangeRatesUnavailable when the source has no rate for the currency
     */
    public function hufPer(Currency $currency): string
    {
        if ($currency === Currency::HUF) {
            return '1';
        }

        return $this->hufPerUnit[$currency->value] ?? throw new ExchangeRatesUnavailable("No exchange rate for {$currency->value}.");
    }
}
