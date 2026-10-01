<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class PeriodEndReminder extends BudgetPushNotification
{
    public function __construct(public int $expectedLeftover) {}

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        return $this->message(
            title: __('Last day of the period'),
            body: __('Expected leftover: :amount. Close the month when you are ready.', ['amount' => money($this->expectedLeftover, $notifiable->settings()->currency)]),
            url: route('month', absolute: false),
            tag: 'period-end',
        );
    }
}
