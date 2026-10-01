<?php

namespace App\Enums;

enum CalcMode: string
{
    case Fixed = 'fixed';
    case Avg = 'avg';
    case Max = 'max';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => __('Fixed'),
            self::Avg => __('Average'),
            self::Max => __('Maximum'),
        };
    }
}
