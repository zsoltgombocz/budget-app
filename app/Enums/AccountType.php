<?php

namespace App\Enums;

enum AccountType: string
{
    case Bank = 'bank';
    case Cash = 'cash';
    case Investment = 'investment';

    public function label(): string
    {
        return match ($this) {
            self::Bank => __('Bank account'),
            self::Cash => __('Cash'),
            self::Investment => __('Investment account'),
        };
    }
}
