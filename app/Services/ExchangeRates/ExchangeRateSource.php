<?php

namespace App\Services\ExchangeRates;

interface ExchangeRateSource
{
    /**
     * The latest official rates.
     *
     * @throws ExchangeRatesUnavailable
     */
    public function latest(): ExchangeRates;
}
