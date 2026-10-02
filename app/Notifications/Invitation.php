<?php

namespace App\Notifications;

use App\Actions\Admin\InviteUser;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class Invitation extends Notification
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
            ->subject(__('You are invited to :app', ['app' => config()->string('app.name')]))
            ->greeting(__('Hi :name!', ['name' => $notifiable->name]))
            ->line(__('You are invited to :app: plan your month, record spending in two taps and see the expected leftover.', ['app' => config()->string('app.name')]))
            ->action(__('Accept the invite'), route('magic-link.show', $this->token))
            ->line(__('The button signs you in and works once, for :days days. Later you can sign in any time with your email: we send a code.', ['days' => InviteUser::DAYS]))
            ->line(__('Tip: open the app on your phone and add it to the Home Screen.'));
    }
}
