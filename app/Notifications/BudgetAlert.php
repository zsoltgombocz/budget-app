<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

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
        $body = $this->remaining > 0
            ? __(':amount left for the rest of the period.', ['amount' => money($this->remaining, $notifiable->settings()->currency)])
            : __('The budget is used up.');

        return $this->message(
            title: __(':category reached :percent% of its budget', ['category' => $this->categoryName, 'percent' => $this->percent]),
            body: $body,
            url: route('month', ['kategoria' => $this->categoryId], absolute: false),
            tag: 'budget-alert-'.$this->categoryId,
        );
    }
}
