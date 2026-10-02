<?php

namespace App\Support;

use Laravel\Pulse\Facades\Pulse;

/**
 * Suspicious sign-in activity, recorded into Pulse for the "Security events" card on the
 * monitoring dashboard. A no-op when Pulse is disabled.
 */
final class SecurityEvents
{
    public const string TYPE = 'security_event';

    public static function record(string $event): void
    {
        $key = json_encode([$event, request()->ip() ?? 'unknown'], JSON_THROW_ON_ERROR);

        Pulse::record(self::TYPE, $key, now()->getTimestamp())->count()->max();
    }

    /**
     * Readable name of an event key.
     */
    public static function label(string $event): string
    {
        return match ($event) {
            'wrong_code' => __('Wrong sign-in code'),
            'sign_in_throttled' => __('Too many sign-in attempts'),
            'unknown_email' => __('Sign-in with an unknown email'),
            'invalid_link' => __('Expired or reused sign-in link'),
            'wrong_dev_password' => __('Wrong dev password'),
            'disabled_user' => __('Disabled user tried to sign in'),
            'unknown_admin_email' => __('Admin sign-in with an unknown email'),
            'wrong_admin_code' => __('Wrong admin sign-in code'),
            'invalid_admin_link' => __('Expired or reused admin sign-in link'),
            default => $event,
        };
    }
}
