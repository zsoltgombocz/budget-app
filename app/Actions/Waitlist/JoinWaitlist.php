<?php

namespace App\Actions\Waitlist;

use App\Models\WaitlistSignup;
use App\Notifications\WaitlistConfirmation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

final class JoinWaitlist
{
    /**
     * Put an address on the 2.0 waitlist and send the confirmation link. Asking again resends the
     * link; a confirmed address gets nothing new. The answer is the same either way.
     */
    public function handle(string $email, ?string $source = null): WaitlistSignup
    {
        $email = Str::lower(trim($email));
        $signup = WaitlistSignup::query()->firstOrNew(['email' => $email]);

        if ($signup->exists && $signup->isConfirmed()) {
            return $signup;
        }

        $signup->fill([
            'token' => $signup->token ?? Str::random(64),
            'source' => $signup->source ?? $source,
        ])->save();

        Notification::route('mail', $email)->notify(new WaitlistConfirmation($signup->token));

        return $signup;
    }
}
