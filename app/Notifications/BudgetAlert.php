<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A watched category reached 80% of what the user set for it (only when they asked for the push).
 */
class BudgetAlert extends BudgetPushNotification
{
    public function __construct(
        public int $categoryId,
        public string $categoryName,
        public int $percent,
        public int $remaining,
    ) {}

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        return $this->message(
            title: 'Morzsa',
            body: __(':category: :percent% of what you set is gone, :left left.', ['category' => $this->categoryName, 'percent' => $this->percent, 'left' => money(max(0, $this->remaining), $notifiable->settings()->currency)]),
            url: route('month', ['kategoria' => $this->categoryId], absolute: false),
            tag: 'budget-alert-'.$this->categoryId,
        );
    }
}
