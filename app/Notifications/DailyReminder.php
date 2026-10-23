<?php

namespace App\Notifications;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The evening reminder, only on a day with nothing written in yet. Morzsa's voice, no guilt.
 */
class DailyReminder extends BudgetPushNotification
{
    public function __construct(public string $date, public int $streak = 0) {}

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        $noSpendUrl = URL::temporarySignedRoute(
            'push.no-spend',
            CarbonImmutable::parse($this->date)->addDays(2),
            ['user' => $notifiable->id, 'date' => $this->date],
        );

        return $this->message(
            title: 'Morzsa',
            body: $this->streak > 1
                ? __('A quiet day? Mark it and the streak goes on (:count).', ['count' => $this->streak])
                : __('What was today? One tap and it is done.'),
            url: route('entry', absolute: false),
            tag: 'daily-reminder',
        )
            ->action(__('Record'), 'record')
            ->action(__('I didn\'t spend today'), 'no-spend')
            ->data([
                'url' => route('entry', absolute: false),
                'actionUrls' => ['record' => route('entry', absolute: false)],
                'noSpendUrl' => $noSpendUrl,
            ]);
    }
}
