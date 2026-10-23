<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The morning of a fixed line's day, all of the day's lines in one notification. What goes out on its
 * own needs nothing; a line paid by hand or a savings line asks for a tap on Today.
 */
class DueItemsReminder extends BudgetPushNotification
{
    /**
     * @param  list<array{name: string, amount: int, kind: string}>  $items  kind: auto, manual or savings
     */
    public function __construct(public array $items) {}

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        $currency = $notifiable->settings()->currency;
        $sentences = [];

        foreach (['auto', 'manual', 'savings'] as $kind) {
            $items = array_values(array_filter($this->items, fn (array $item): bool => $item['kind'] === $kind));

            if ($items === []) {
                continue;
            }

            $names = implode(', ', array_map(fn (array $item): string => $item['name'].' '.money($item['amount'], $currency), $items));
            $sentences[] = match ($kind) {
                'auto' => __('Goes out today: :items. Taken off in advance, no need to record it.', ['items' => $names]),
                'manual' => __('Due today: :items. Mark it when it is done.', ['items' => $names]),
                default => __('Set aside today: :items. Mark it when it is done.', ['items' => $names]),
            };
        }

        return $this->message(
            title: 'Morzsa',
            body: implode(' ', $sentences),
            url: route('dashboard', absolute: false),
            tag: 'due-items',
        );
    }
}
