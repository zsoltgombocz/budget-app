<?php

namespace App\Notifications;

use App\Actions\Auth\SendMagicLink;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MagicLoginLink extends Notification
{
    public function __construct(public string $token) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('Your sign-in link'))
            ->line(__('Tap the button to sign in to :app. No password needed.', ['app' => config()->string('app.name')]))
            ->action(__('Sign in'), route('magic-link.show', $this->token))
            ->line(__('The link works once, for :minutes minutes.', ['minutes' => SendMagicLink::MINUTES]))
            ->line(__('If you did not ask for it, just ignore this email.'));
    }
}
