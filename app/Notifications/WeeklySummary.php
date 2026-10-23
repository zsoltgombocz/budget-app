<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Sunday evening, three facts: what went this week, where most of it went, the streak.
 */
class WeeklySummary extends BudgetPushNotification
{
    public function __construct(public int $spent, public ?string $topCategory, public int $streak) {}

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        $sentences = [__('This week :amount went.', ['amount' => money($this->spent, $notifiable->settings()->currency)])];

        if ($this->topCategory !== null) {
            $sentences[] = __('Most on: :category.', ['category' => $this->topCategory]);
        }

        if ($this->streak > 1) {
            $sentences[] = __(':count days in a row.', ['count' => $this->streak]);
        }

        return $this->message(
            title: 'Morzsa',
            body: implode(' ', $sentences),
            url: route('month', absolute: false),
            tag: 'weekly-summary',
        );
    }
}
