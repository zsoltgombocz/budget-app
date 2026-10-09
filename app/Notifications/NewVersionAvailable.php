<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\Changelog;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A new app version is out; opens What's new.
 */
class NewVersionAvailable extends BudgetPushNotification
{
    public function __construct(public string $version) {}

    public function toWebPush(User $notifiable, Notification $notification): WebPushMessage
    {
        $entry = collect(Changelog::all())->firstWhere('version', $this->version);
        $changes = $entry === null ? [] : Changelog::changes($entry);
        $first = $changes[0] ?? '';
        $more = count($changes) - 1;

        return $this->message(
            title: __('MoneySight :version is out', ['version' => $this->version]),
            body: Str::limit($first, 140).($more > 0 ? ' '.trans_choice('{1} (+1 more)|[2,*] (+:count more)', $more, ['count' => $more]) : ''),
            url: route('changelog', absolute: false),
            tag: 'new-version',
        );
    }
}
