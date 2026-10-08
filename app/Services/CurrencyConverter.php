<?php

namespace App\Services;

use App\Enums\Currency;
use InvalidArgumentException;
use RoundingMode;

/**
 * Converts an amount stored in one currency's smallest unit into another's, via the forint
 * (the way MNB quotes every rate). Exact decimal math, rounded once, half away from zero.
 */
final class CurrencyConverter
{
    private const int SCALE = 16;

    /**
     * @param  string  $hufPerFrom  forints per one unit of $from ("1" for HUF)
     * @param  string  $hufPerTo  forints per one unit of $to ("1" for HUF)
     */
    public function convert(int $amount, Currency $from, Currency $to, string $hufPerFrom, string $hufPerTo): int
    {
        if (! is_numeric($hufPerFrom) || ! is_numeric($hufPerTo) || bccomp($hufPerFrom, '0', self::SCALE) <= 0 || bccomp($hufPerTo, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException('Exchange rates must be positive numbers.');
        }

        if ($from === $to) {
            return $amount;
        }

        $forints = bcdiv(bcmul((string) $amount, $hufPerFrom, self::SCALE), (string) $from->minorPerMajor(), self::SCALE);
        $target = bcdiv(bcmul($forints, (string) $to->minorPerMajor(), self::SCALE), $hufPerTo, self::SCALE);

        return (int) bcround($target, 0, RoundingMode::HalfAwayFromZero);
    }
}
