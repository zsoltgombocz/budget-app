<?php

namespace App\Notifications;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use NotificationChannels\WebPush\WebPushMessage;

class DailyReminder extends BudgetPushNotification
{
    /**
     * @param  int  $streakAtRisk  the streak that ends tonight if nothing is recorded (no rest day left), else 0
     */
    public function __construct(public string $date, public int $streakAtRisk = 0) {}

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        $noSpendUrl = URL::temporarySignedRoute(
            'push.no-spend',
            CarbonImmutable::parse($this->date)->addDays(2),
            ['user' => $notifiable->id, 'date' => $this->date],
        );

        return $this->message(
            title: $this->streakAtRisk > 1
                ? __('Your :count-day streak ends tonight', ['count' => $this->streakAtRisk])
                : __('Did you record today\'s spending?'),
            body: $this->streakAtRisk > 1
                ? __('Record a spending or mark that you spent nothing today.')
                : __('One tap and you are done.'),
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
