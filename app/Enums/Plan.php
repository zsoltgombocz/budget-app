<?php

namespace App\Enums;

/**
 * What a user has: Alap (free), Plusz (paid or set by an admin), or the 14-day Plusz trial.
 */
enum Plan: string
{
    case Basic = 'basic';
    case Plus = 'plus';
    case PlusTrial = 'plus_trial';

    public function label(): string
    {
        return match ($this) {
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
