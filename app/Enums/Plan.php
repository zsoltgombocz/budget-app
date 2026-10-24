<?php

namespace App\Enums;

/**
 * What a user has (2.0): the 30-day trial (it gives Alap), Alap, Plusz, the one 14-day Plusz offer.
 */
enum Plan: string
{
    case Trial = 'trial';
    case Basic = 'basic';
    case Plus = 'plus';
    case PlusTrial = 'plus_trial';

    public function label(): string
    {
        return match ($this) {
            self::Trial => __('Trial'),
            self::Basic => __('Alap'),
            self::Plus => __('Plusz'),
            self::PlusTrial => __('Plusz trial'),
        };
    }

    public function isPlus(): bool
    {
        return $this === self::Plus || $this === self::PlusTrial;
    }
}
