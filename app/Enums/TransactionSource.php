<?php

namespace App\Enums;

enum TransactionSource: string
{
    case Manual = 'manual';
    case Import = 'import';
}
