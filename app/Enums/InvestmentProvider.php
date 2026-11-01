<?php

namespace App\Enums;

/**
 * Where an investment account's value comes from.
 */
enum InvestmentProvider: string
{
    /** Typed in by hand (any broker, e.g. ActivTrades or Lightyear). */
    case Manual = 'manual';

    /** Interactive Brokers, read daily through a Flex Web Service token (read-only reports). */
    case Ibkr = 'ibkr';

    public function label(): string
    {
        return match ($this) {
            self::Manual => __('Entered by hand'),
            self::Ibkr => __('Interactive Brokers'),
        };
    }
}
