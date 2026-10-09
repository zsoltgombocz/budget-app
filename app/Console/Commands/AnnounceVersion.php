<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\NewVersionAvailable;
use App\Services\NotificationLogger;
use App\Support\Changelog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use NotificationChannels\WebPush\PushSubscription;

#[Signature('budget:announce-version')]
#[Description('Tell users with push notifications about the current app version, once per version (run after a deploy)')]
class AnnounceVersion extends Command
{
    public function handle(NotificationLogger $logger): int
    {
        $version = Changelog::version();
        $sent = 0;

        User::query()
            ->whereIn('id', PushSubscription::query()->where('subscribable_type', (new User)->getMorphClass())->select('subscribable_id'))
            ->with('budgetSetting')
            ->lazyById()
            ->each(function (User $user) use ($version, $logger, &$sent): void {
                $settings = $user->settings();

                if (! $settings->isOnboarded() || $settings->notified_version === $version) {
                    return;
                }

                if ($settings->version_reminder_enabled) {
                    $notification = new NewVersionAvailable($version);
                    $logger->queued($user, $notification);
                    $user->notify($notification);
                    $sent++;
                }

                $settings->update(['notified_version' => $version]);
            });

        $this->info("Version {$version} announced to {$sent} user(s).");

        return self::SUCCESS;
    }
}
