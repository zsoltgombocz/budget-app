<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class PeriodEndReminder extends BudgetPushNotification
{
    /**
     * @param  int  $leftover  the leftover as things stand, the main number on Today
     */
    public function __construct(public int $leftover) {}

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        return $this->message(
            title: __('Last day of the period'),
            body: __('Leftover as things stand: :amount. Close the month when you are ready.', ['amount' => money($this->leftover, $notifiable->settings()->currency)]),
            url: route('month', absolute: false),
            tag: 'period-end',
        );
    }
}
