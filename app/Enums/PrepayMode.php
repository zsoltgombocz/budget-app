<?php

namespace App\Enums;

enum PrepayMode: string
{
    case ReduceInstallment = 'reduce_installment';
    case ReduceTerm = 'reduce_term';

    public function label(): string
    {
        return match ($this) {
            self::ReduceInstallment => __('Reduce installment'),
            self::ReduceTerm => __('Reduce term'),
        };
    }
}
