<?php

namespace App\Services;

use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\BudgetPushNotification;
use Illuminate\Support\Str;
use Minishlink\WebPush\MessageSentReport;

/**
 * Keeps a per-user log of push notifications: what the scheduler decided (queued or skipped,
 * with the reason) and what the push service (Apple, Google, Mozilla) answered when it was sent.
 */
final class NotificationLogger
{
    /**
     * Logs a notification that is about to be queued; gives it an id to match the answer later.
     */
    public function queued(User $user, BudgetPushNotification $notification): void
    {
        $notification->id = (string) Str::uuid();

        $user->notificationLogs()->create([
            'notification_id' => $notification->id,
            'type' => $notification->logType(),
            'status' => NotificationLog::QUEUED,
        ]);
    }

    public function skipped(User $user, string $type, string $reason): void
    {
        $user->notificationLogs()->create([
            'type' => $type,
            'status' => NotificationLog::SKIPPED,
            'reason' => $reason,
        ]);
    }

    /**
     * Records the push service's answer for a sent notification.
     *
     * @param  array<mixed>  $reports
     */
    public function sent(User $user, BudgetPushNotification $notification, array $reports): void
    {
        $reports = array_values(array_filter($reports, fn (mixed $report): bool => $report instanceof MessageSentReport));
        $failed = array_values(array_filter($reports, fn (MessageSentReport $report): bool => ! $report->isSuccess()));
        $report = $failed[0] ?? $reports[0] ?? null;

        $attributes = [
            'type' => $notification->logType(),
            'status' => $report === null || $failed !== [] ? NotificationLog::FAILED : NotificationLog::SENT,
            'reason' => match (true) {
                $report === null => 'no push subscription',
                $failed !== [] => Str::limit($report->getReason(), 250, ''),
                default => null,
            },
            'push_status' => $report?->getResponse()?->getStatusCode(),
            'push_host' => $report === null ? null : parse_url($report->getEndpoint(), PHP_URL_HOST),
        ];

        $log = $user->notificationLogs()->where('notification_id', $notification->id)->first();

        if ($log instanceof NotificationLog) {
            $log->update($attributes);

            return;
        }

        $user->notificationLogs()->create(['notification_id' => $notification->id] + $attributes);
    }
}
