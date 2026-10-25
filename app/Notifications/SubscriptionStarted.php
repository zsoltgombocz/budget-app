<?php

namespace App\Notifications;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The confirmation of a new Plusz subscription on a durable medium (45/2014. Korm. r. 18. §):
 * what was bought, the price and renewal, how to cancel or withdraw, and the terms in force.
 */
class SubscriptionStarted extends Notification
{
    public function __construct(
        public string $price,
        public bool $yearly,
        public ?CarbonInterface $firstCharge = null,
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
        $message = (new MailMessage)
            ->subject(__('Your Plusz subscription has started'))
            ->greeting(__('Hi :name!', ['name' => $notifiable->name]))
            ->line(__('Thank you! You subscribed to MoneySight Plusz: :price, renewing automatically every :period until you cancel it.', ['price' => $this->price, 'period' => $this->yearly ? __('year') : __('month')]));

        if ($this->firstCharge instanceof CarbonInterface) {
            $message->line(__('The first payment is on :date, when your free trial ends.', ['date' => $this->firstCharge->format('Y. m. d.')]));
        }

        return $message
            ->line(__('You can cancel it any time under Settings → Subscription; Plusz stays until the end of the paid time.'))
            ->line(__('Within :days days you can withdraw from it there and get the full amount back, without giving a reason.', ['days' => config()->integer('budget.withdrawal_days')]))
            ->action(__('Terms (version :version)', ['version' => config()->string('budget.legal.version')]), route('legal', 'aszf'))
            ->line(config()->string('budget.operator.vat_note'));
    }
}
