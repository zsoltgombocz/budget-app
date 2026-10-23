<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The morning after a month ended without a closing: one decision is waiting.
 */
class CloseReminder extends BudgetPushNotification
{
    public function __construct(public int $periodId, public string $month, public int $leftover) {}

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        $currency = $notifiable->settings()->currency;

        return $this->message(
            title: 'Morzsa',
            body: $this->leftover >= 0
                ? __(':month left :amount. What should happen to it?', ['month' => $this->month, 'amount' => money($this->leftover, $currency)])
                : __(':month went :amount over. What shall we do with it?', ['month' => $this->month, 'amount' => money(-$this->leftover, $currency)]),
            url: route('close', $this->periodId, absolute: false),
            tag: 'close-'.$this->periodId,
        );
    }
}
