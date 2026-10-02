<?php

namespace App\Actions\Admin;

use App\Models\Admin;
use App\Notifications\AdminSignInLink;
use App\Support\SecurityEvents;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Passwordless sign-in to the admin panel: an emailed 6-digit code plus a single-use link.
 * Unknown addresses get nothing; the login page answers the same either way.
 */
final class AdminSignIn
{
    public const int MINUTES = 15;

    public const int MAX_ATTEMPTS = 5;

    public function send(string $email): void
    {
        $email = Str::lower(trim($email));
        $admin = Admin::query()->where('email', $email)->first();

        if ($admin === null) {
            SecurityEvents::record('unknown_admin_email');

            return;
        }

        $token = Str::random(64);
        Cache::put($this->linkKey($token), $admin->id, now()->addMinutes(self::MINUTES));

        $code = (string) random_int(100000, 999999);
        Cache::put($this->codeKey($email), ['admin' => $admin->id, 'hash' => hash('sha256', $code), 'attempts' => 0], now()->addMinutes(self::MINUTES));

        $admin->notifyNow(new AdminSignInLink($token, $code));
    }

    /**
     * Use up a link token and return its admin, or null when it is unknown, expired or used.
     */
    public function consumeLink(string $token): ?Admin
    {
        $adminId = Cache::pull($this->linkKey($token));

        return is_int($adminId) ? Admin::query()->find($adminId) : null;
    }

    /**
     * Use up a code typed for the address. After MAX_ATTEMPTS wrong tries the code stops working.
     */
    public function consumeCode(string $email, string $code): ?Admin
    {
        $key = $this->codeKey(Str::lower(trim($email)));
        $entry = Cache::get($key);

        if (! is_array($entry) || ! is_int($entry['admin'] ?? null) || ! is_string($entry['hash'] ?? null) || ! is_int($entry['attempts'] ?? null)) {
            return null;
        }

        if (! hash_equals($entry['hash'], hash('sha256', preg_replace('/\D/', '', $code) ?? ''))) {
            $entry['attempts']++;
            $entry['attempts'] >= self::MAX_ATTEMPTS ? Cache::forget($key) : Cache::put($key, $entry, now()->addMinutes(self::MINUTES));

            return null;
        }

        Cache::forget($key);

        return Admin::query()->find($entry['admin']);
    }

    private function codeKey(string $email): string
    {
        return 'admin-login-code:'.hash('sha256', $email);
    }

    private function linkKey(string $token): string
    {
        return 'admin-login-link:'.hash('sha256', $token);
    }
}
