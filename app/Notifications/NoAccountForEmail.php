<?php

namespace App\Notifications;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent instead of a sign-in code when someone signs in with an address that has no account,
 * so they learn why no code came. The form answers the same either way.
 */
class NoAccountForEmail extends Notification
{
    /**
     * @return list<string>
     */
    public function via(AnonymousNotifiable $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(AnonymousNotifiable $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('No account for this address yet'))
            ->line(__('Someone tried to sign in with this email address, but it has no account yet.'))
            ->line(__('If it was you, register with this address and you get your sign-in code right away:'))
            ->action(__('Register'), route('register'))
            ->line(__('If you did not ask for it, just ignore this email.'));
    }
}
