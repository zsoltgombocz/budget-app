<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class PaydayReminder extends BudgetPushNotification
{
    /**
     * @param  list<array{name: string, amount: int}>  $transfers
     */
    public function __construct(public array $transfers) {}

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        $currency = $notifiable->settings()->currency;
        $lines = array_map(fn (array $transfer): string => $transfer['name'].': '.money($transfer['amount'], $currency), $this->transfers);

        return $this->message(
            title: __('Payday! Time for the transfers'),
            body: $lines === [] ? __('A new period starts today.') : implode("\n", $lines),
            url: route('dashboard', absolute: false),
            tag: 'payday',
        );
    }
}
