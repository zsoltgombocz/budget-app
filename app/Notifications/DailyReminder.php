<?php

namespace App\Notifications;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use NotificationChannels\WebPush\WebPushMessage;

class DailyReminder extends BudgetPushNotification
{
    public function __construct(public string $date) {}

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        $noSpendUrl = URL::temporarySignedRoute(
            'push.no-spend',
            CarbonImmutable::parse($this->date)->addDays(2),
            ['user' => $notifiable->id, 'date' => $this->date],
        );

        return $this->message(
            title: __('Did you record today\'s spending?'),
            body: __('One tap and you are done.'),
            url: route('entry', absolute: false),
            tag: 'daily-reminder',
        )
            ->action(__('Record'), 'record')
            ->action(__('Nothing today'), 'no-spend')
            ->data([
                'url' => route('entry', absolute: false),
                'actionUrls' => ['record' => route('entry', absolute: false)],
                'noSpendUrl' => $noSpendUrl,
            ]);
    }
}
