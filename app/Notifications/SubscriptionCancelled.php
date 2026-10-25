<?php

namespace App\Notifications;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirms a cancellation: Plusz stays until the end of the paid time, and it can still be taken back.
 */
class SubscriptionCancelled extends Notification
{
    public function __construct(public ?CarbonInterface $endsAt) {}

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
            ->subject(__('You cancelled Plusz'))
            ->greeting(__('Hi :name!', ['name' => $notifiable->name]))
            ->line($this->endsAt instanceof CarbonInterface
                ? __('Your Plusz subscription is cancelled. Plusz stays until :date, then Alap comes back. Nothing is charged any more and nothing is deleted.', ['date' => $this->endsAt->format('Y. m. d.')])
                : __('Your Plusz subscription is cancelled. Nothing is charged any more and nothing is deleted.'))
            ->line(__('Changed your mind? Until then you can take it back under Settings → Subscription.'))
            ->action(__('Subscription'), route('subscription'));
    }
}
