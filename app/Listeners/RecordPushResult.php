<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\BudgetPushNotification;
use App\Services\NotificationLogger;
use Illuminate\Notifications\Events\NotificationSent;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Writes the push service's answer into the user's notification log.
 */
class RecordPushResult
{
    public function __construct(private readonly NotificationLogger $logger) {}

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== WebPushChannel::class
            || ! $event->notification instanceof BudgetPushNotification
            || ! $event->notifiable instanceof User) {
            return;
        }

        $this->logger->sent($event->notifiable, $event->notification, is_array($event->response) ? $event->response : []);
    }
}
