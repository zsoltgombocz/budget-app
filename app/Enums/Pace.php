<?php

namespace App\Enums;

/**
 * How the month's spending compares with the share of the month that has passed.
 */
enum Pace: string
{
    case Good = 'good';
    case Fast = 'fast';
    case SlowDown = 'slow_down';

    public function label(): string
    {
        return match ($this) {
            self::Good => __('On a good pace'),
            self::Fast => __('Spending a bit fast'),
            self::SlowDown => __('Slow down a little'),
        };
    }
}
