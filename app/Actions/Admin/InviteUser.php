<?php

namespace App\Actions\Admin;

use App\Actions\Auth\SendMagicLink;
use App\Models\User;
use App\Notifications\Invitation;
use Illuminate\Support\Str;

/**
 * Creates the account (unless it exists) and emails an invite with a sign-in link.
 * The app is invite only in production, so this is how people get in.
 */
final readonly class InviteUser
{
    /**
     * How long the link in the invite works.
     */
    public const int DAYS = 7;

    public function __construct(private SendMagicLink $sendMagicLink) {}

    public function handle(string $name, string $email): User
    {
        $email = Str::lower(trim($email));

        $user = User::query()->firstOrCreate(['email' => $email], [
            'name' => trim($name),
            'password' => Str::random(64),
        ]);

        $user->forceFill(['invited_at' => now()])->save();

        $token = $this->sendMagicLink->issueLink($user, self::DAYS * 24 * 60);
        $user->notifyNow(new Invitation($token));

        return $user;
    }
}
