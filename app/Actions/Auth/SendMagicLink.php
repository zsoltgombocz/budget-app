<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Notifications\MagicLoginLink;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class SendMagicLink
{
    /**
     * How long a sign-in link works.
     */
    public const int MINUTES = 15;

    /**
     * Email a single-use sign-in link. With a name, an account is created first when the
     * address is new. Unknown addresses without a name get nothing, but the caller shows the
     * same message either way so the form does not reveal which addresses have an account.
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
            return null;
        }

        $token = Str::random(64);
        Cache::put(self::cacheKey($token), $user->id, now()->addMinutes(self::MINUTES));

        $user->notifyNow(new MagicLoginLink($token));

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

    public static function cacheKey(string $token): string
    {
        return 'magic-link:'.hash('sha256', $token);
    }
}
