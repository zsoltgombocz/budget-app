<?php

namespace App\Enums;

enum CaptureStatus: string
{
    /** Became a spending right away. */
    case Recorded = 'recorded';
    /** Waits for the user: see the reason. Does not count until they decide. */
    case Pending = 'pending';
    /** Not a spending (income, transfer, no amount…); kept only for the status display. */
    case Ignored = 'ignored';
    /** The user dismissed it. */
    case Dismissed = 'dismissed';
    /** A connection test from the phone. */
    case Test = 'test';

    public function label(): string
    {
        return match ($this) {
            self::Recorded => __('Recorded'),
            self::Pending => __('Waiting for you'),
            self::Ignored => __('Skipped'),
            self::Dismissed => __('Dismissed'),
            self::Test => __('Connection test'),
        };
    }
}
