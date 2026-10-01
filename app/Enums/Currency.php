<?php

namespace App\Enums;

enum Currency: string
{
    case HUF = 'HUF';
    case EUR = 'EUR';
    case USD = 'USD';
    case GBP = 'GBP';
    case CHF = 'CHF';
    case CZK = 'CZK';
    case PLN = 'PLN';
    case RON = 'RON';

    /**
     * Number of decimals of the smallest unit we store amounts in.
     * HUF is stored in whole forints, the others in cents.
     */
    public function decimals(): int
    {
        return match ($this) {
            self::HUF => 0,
            default => 2,
        };
    }

    /**
     * Localised currency symbol, e.g. "Ft" or "€".
     */
    public function symbol(?string $locale = null): string
    {
        $formatter = new \NumberFormatter(($locale ?? app()->getLocale()).'@currency='.$this->value, \NumberFormatter::CURRENCY);

        return $formatter->getSymbol(\NumberFormatter::CURRENCY_SYMBOL) ?: $this->value;
    }

    public function minorPerMajor(): int
    {
        return 10 ** $this->decimals();
    }
}
