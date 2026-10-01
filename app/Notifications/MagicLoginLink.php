<?php

namespace App\Notifications;

use App\Actions\Auth\SendMagicLink;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class MagicLoginLink extends Notification
{
    public function __construct(
        public string $token,
        public string $code,
    ) {}

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
            ->subject(__(':code is your sign-in code', ['code' => $this->code]))
            ->line(__('Type this code in the app to sign in:'))
            ->line(new HtmlString('<p class="code">'.e(substr($this->code, 0, 3).' '.substr($this->code, 3)).'</p>'))
            ->line(__('The code works once, for :minutes minutes.', ['minutes' => SendMagicLink::MINUTES]))
            ->line(__('On a computer or in the browser you can also use the button:'))
            ->action(__('Sign in'), route('magic-link.show', $this->token))
            ->line(__('If you did not ask for it, just ignore this email.'));
    }
}
