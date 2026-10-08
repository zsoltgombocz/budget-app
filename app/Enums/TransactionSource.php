<?php

namespace App\Enums;

enum TransactionSource: string
{
    case Manual = 'manual';
    case Import = 'import';
    /** Sent in by a phone automation (iOS Shortcut, Android notification). */
    case Auto = 'auto';
}
