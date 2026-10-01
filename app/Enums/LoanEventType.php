<?php

namespace App\Enums;

enum LoanEventType: string
{
    case Payment = 'payment';
    case Prepayment = 'prepayment';
    case RateChange = 'rate_change';
}
