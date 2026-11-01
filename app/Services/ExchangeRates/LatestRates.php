<?php

namespace App\Services\ExchangeRates;

use App\Enums\Currency;
use App\Services\CurrencyConverter;
use Illuminate\Support\Facades\Cache;

/**
 * Today's official rates for showing an amount in another currency (an investment account in
 * the base currency). Cached for a few hours; without rates the converted amount is simply left out.
 */
final readonly class LatestRates
{
    public function __construct(
        private ExchangeRateSource $source,
        private CurrencyConverter $converter,
    ) {}

    public function convert(int $amount, Currency $from, Currency $to): ?int
    {
        if ($from === $to) {
            return $amount;
        }

        $rates = $this->rates();

        if (! $rates instanceof ExchangeRates) {
            return null;
        }

        try {
            return $this->converter->convert($amount, $from, $to, $rates->hufPer($from), $rates->hufPer($to));
        } catch (ExchangeRatesUnavailable) {
            return null;
        }
    }

    private function rates(): ?ExchangeRates
    {
        $cached = Cache::get('exchange-rates:latest');

        if (is_array($cached) && is_string($cached['date'] ?? null) && is_array($cached['rates'] ?? null)) {
            $rates = array_filter($cached['rates'], fn (mixed $rate, mixed $code): bool => is_string($code) && is_string($rate), ARRAY_FILTER_USE_BOTH);

            return new ExchangeRates($cached['date'], $rates);
        }

        try {
            $rates = $this->source->latest();
        } catch (ExchangeRatesUnavailable) {
            return null;
        }

        Cache::put('exchange-rates:latest', ['date' => $rates->date, 'rates' => $rates->hufPerUnit], now()->addHours(6));

        return $rates;
    }
}
