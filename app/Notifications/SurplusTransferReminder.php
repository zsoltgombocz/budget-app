<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

class SurplusTransferReminder extends BudgetPushNotification
{
    public function __construct(
        public int $periodCloseId,
        public int $amount,
        public string $accountName,
    ) {}

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        return $this->message(
            title: __('Transfer :amount to :account', ['amount' => money($this->amount, $notifiable->settings()->currency), 'account' => $this->accountName]),
            body: __('The month-end leftover is waiting. Mark it as transferred in the app.'),
            url: route('dashboard', absolute: false),
            tag: 'surplus-transfer-'.$this->periodCloseId,
        );
    }
}
