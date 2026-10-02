<?php

namespace App\Notifications;

use App\Actions\Admin\AdminSignIn;
use App\Models\Admin;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class AdminSignInLink extends Notification
{
    public function __construct(
        public string $token,
        public string $code,
    ) {}

    /**
     * @return list<string>
     */
    public function via(Admin $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(Admin $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__(':code is your admin sign-in code', ['code' => $this->code]))
            ->line(__('Your admin sign-in code is :code. Type it on the admin login page:', ['code' => $this->code]))
            ->line(new HtmlString('<p class="code">'.e($this->code).'</p>'))
            ->line(__('Or sign in with the button. Both work once, for :minutes minutes.', ['minutes' => AdminSignIn::MINUTES]))
            ->action(__('Sign in to the admin'), route('filament.admin.auth.link', $this->token))
            ->line(__('If you did not ask for it, just ignore this email.'));
    }
}
