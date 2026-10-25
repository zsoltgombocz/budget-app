<?php

namespace App\Notifications;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirms a withdrawal right away, with its date and time and what happens to the money.
 */
class SubscriptionWithdrawn extends Notification
{
    public function __construct(
        public CarbonInterface $at,
        public ?string $refund,
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
            ->subject(__('Withdrawal from the Plusz subscription'))
            ->greeting(__('Hi :name!', ['name' => $notifiable->name]))
            ->line(__('We received your withdrawal from the MoneySight Plusz subscription on :date.', ['date' => $this->at->format('Y. m. d. H:i')]))
            ->line($this->refund !== null
                ? __('We refund :amount to your card; it usually arrives within 5–10 days.', ['amount' => $this->refund])
                : __('Nothing had been charged yet, so there is nothing to refund.'))
            ->line(__('Your account stays on Alap, nothing is deleted.'));
    }
}
