<?php

namespace App\Services\ExchangeRates;

use RuntimeException;

/**
 * The exchange rate source could not be reached or gave an unusable answer.
 */
final class ExchangeRatesUnavailable extends RuntimeException {}
