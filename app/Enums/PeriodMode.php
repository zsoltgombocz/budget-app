<?php

namespace App\Enums;

enum PeriodMode: string
{
    case Payday = 'payday';
    case Calendar = 'calendar';

    public function label(): string
    {
        return match ($this) {
            self::Payday => __('Payday to payday'),
            self::Calendar => __('Calendar month'),
        };
    }
}
