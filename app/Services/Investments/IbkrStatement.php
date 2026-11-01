<?php

namespace App\Services\Investments;

use App\Enums\Currency;

/**
 * What an IBKR Flex report says about the account: its base currency and its total value per day.
 */
final readonly class IbkrStatement
{
    /**
     * @param  array<string, int>  $values  Y-m-d => total value in the currency's smallest unit, oldest first
     */
    public function __construct(
        public Currency $currency,
        public array $values,
    ) {}
}
