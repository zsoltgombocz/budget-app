<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Base for the app's Web Push notifications. Sent in the user's language
 * (User implements HasLocalePreference).
 */
abstract class BudgetPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return [WebPushChannel::class];
    }

    abstract public function toWebPush(User $notifiable, Notification $notification): WebPushMessage;

    /**
     * Key of this notification in the notification log, e.g. "daily-reminder".
     */
    public function logType(): string
    {
        return Str::kebab(class_basename($this));
    }

    protected function message(string $title, string $body, string $url, string $tag): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($title)
            ->body($body)
            ->icon('/icons/icon-192.png')
            ->badge('/icons/badge-96.png')
            ->tag($tag)
            ->data(['url' => $url])
            ->options(['TTL' => 6 * 3600, 'urgency' => 'normal']);
    }
}
