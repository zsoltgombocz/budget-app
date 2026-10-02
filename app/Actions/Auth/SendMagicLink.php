<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Notifications\MagicLoginLink;
use App\Notifications\NoAccountForEmail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

final class SendMagicLink
{
    /**
     * How long a sign-in link and code work.
     */
    public const int MINUTES = 15;

    /**
     * Wrong code attempts before the code is thrown away.
     */
    public const int MAX_ATTEMPTS = 5;

    /**
     * Email a single-use sign-in link. With a name, an account is created first when the
     * address is new. Unknown addresses without a name get a "register first" email instead; the
     * caller shows the same message either way so the form does not reveal which addresses have an account.
     */
    public function handle(string $email, ?string $name = null): ?User
    {
        $email = Str::lower(trim($email));
        $user = User::query()->where('email', $email)->first();

        if ($user === null && filled($name)) {
            $user = User::query()->create([
                'name' => trim($name),
                'email' => $email,
                'password' => Str::random(64),
            ]);
        }

        if ($user === null) {
            Notification::route('mail', $email)->notifyNow(new NoAccountForEmail);

            return null;
        }

        $token = Str::random(64);
        Cache::put(self::cacheKey($token), $user->id, now()->addMinutes(self::MINUTES));

        // The code is for the installed app: links open the browser, which has its own session.
        $code = (string) random_int(100000, 999999);
        Cache::put(self::codeKey($email), ['user' => $user->id, 'hash' => hash('sha256', $code), 'attempts' => 0], now()->addMinutes(self::MINUTES));

        $user->notifyNow(new MagicLoginLink($token, $code));

        return $user;
    }

    /**
     * Use up a token and return its user, or null when it is unknown, expired or used.
     */
    public function consume(string $token): ?User
    {
        $userId = Cache::pull(self::cacheKey($token));

        return is_int($userId) ? User::query()->find($userId) : null;
    }

    /**
     * Use up a sign-in code typed for the address. Wrong attempts count; after
     * MAX_ATTEMPTS the code stops working.
     */
    public function consumeCode(string $email, string $code): ?User
    {
        $key = self::codeKey(Str::lower(trim($email)));
        $entry = Cache::get($key);

        if (! is_array($entry) || ! is_int($entry['user'] ?? null) || ! is_string($entry['hash'] ?? null) || ! is_int($entry['attempts'] ?? null)) {
            return null;
        }

        $code = preg_replace('/\D/', '', $code) ?? '';

        if (! hash_equals($entry['hash'], hash('sha256', $code))) {
            $entry['attempts']++;
            $entry['attempts'] >= self::MAX_ATTEMPTS ? Cache::forget($key) : Cache::put($key, $entry, now()->addMinutes(self::MINUTES));

            return null;
        }

        Cache::forget($key);

        return User::query()->find($entry['user']);
    }

    public static function codeKey(string $email): string
    {
        return 'login-code:'.hash('sha256', $email);
    }

    public static function cacheKey(string $token): string
    {
        return 'magic-link:'.hash('sha256', $token);
    }
}
