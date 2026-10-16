<?php

namespace App\Enums;

/**
 * Where a fixed item stands in a period: still open, ticked off as paid, or marked as not paid.
 */
enum LineStatus: string
{
    case Open = 'open';
    case Paid = 'paid';
    case Skipped = 'skipped';
}
