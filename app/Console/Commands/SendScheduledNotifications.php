<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\NotificationScheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use NotificationChannels\WebPush\PushSubscription;
use Throwable;

#[Signature('budget:notify')]
#[Description('Send the daily reminder, payday and period end push notifications that are due')]
class SendScheduledNotifications extends Command
{
    public function handle(NotificationScheduler $scheduler): int
    {
        User::query()
            ->whereIn('id', PushSubscription::query()->where('subscribable_type', (new User)->getMorphClass())->select('subscribable_id'))
            ->with('budgetSetting')
            ->lazyById()
            ->each(function (User $user) use ($scheduler): void {
                try {
                    $sent = $scheduler->run($user);

                    if ($sent !== []) {
                        $this->line("User {$user->id}: ".implode(', ', $sent));
                    }
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

        return self::SUCCESS;
    }
}
