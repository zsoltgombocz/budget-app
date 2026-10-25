<?php

namespace App\Notifications;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Double opt-in for the 2.0 waitlist: the address counts only after this link is opened.
 */
class WaitlistConfirmation extends Notification
{
    public function __construct(public string $token) {}

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
            ->subject(__('Confirm: we tell you when MoneySight 2.0 launches'))
            ->line(__('Someone asked us with this address to write when MoneySight 2.0 launches. If it was you, confirm it:'))
            ->action(__('Yes, tell me'), route('waitlist.confirm', $this->token))
            ->line(__('We only write about the launch, at most a few times. Every e-mail has a link to unsubscribe, and then we delete your address.'))
            ->line(__('If it was not you, ignore this e-mail and we delete the address.'))
            ->line(__('Unsubscribe and delete my address: :url', ['url' => route('waitlist.unsubscribe', $this->token)]));
    }
}
