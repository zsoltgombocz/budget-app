<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class DueItemsReminder extends BudgetPushNotification
{
    /**
     * @param  list<array{name: string, amount: int}>  $items
     */
    public function __construct(public array $items) {}

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        $currency = $notifiable->settings()->currency;
        $lines = array_map(fn (array $item): string => $item['name'].': '.money($item['amount'], $currency), $this->items);

        return $this->message(
            title: trans_choice('{1} A fixed item is due today|[2,*] :count fixed items are due today', count($this->items), ['count' => count($this->items)]),
            body: implode("\n", $lines),
            url: route('dashboard', absolute: false),
            tag: 'due-items',
        );
    }
}
