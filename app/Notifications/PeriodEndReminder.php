<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class PeriodEndReminder extends BudgetPushNotification
{
    /**
     * @param  int  $remaining  "Még erre a hónapra" on the last day, the main number on Today
     */
    public function __construct(public int $remaining) {}

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        return $this->message(
            title: 'Morzsa',
            body: __('A new month starts tomorrow. This month still has :amount.', ['amount' => money($this->remaining, $notifiable->settings()->currency)]),
            url: route('dashboard', absolute: false),
            tag: 'period-end',
        );
    }
}
