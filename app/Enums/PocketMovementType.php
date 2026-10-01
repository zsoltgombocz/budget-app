<?php

namespace App\Enums;

enum PocketMovementType: string
{
    case Deposit = 'deposit';
    case Withdraw = 'withdraw';
    case Prepay = 'prepay';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => __('Deposit'),
            self::Withdraw => __('Withdrawal'),
            self::Prepay => __('Prepayment'),
        };
    }
}
