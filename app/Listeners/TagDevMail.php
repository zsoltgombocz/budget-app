<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;

/**
 * Prefixes every subject with [DEV] on the dev stack, so test mails are easy to tell apart.
 */
class TagDevMail
{
    public function handle(MessageSending $event): void
    {
        if (! app()->environment('staging')) {
            return;
        }

        $event->message->subject('[DEV] '.$event->message->getSubject());
    }
}
