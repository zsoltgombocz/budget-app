<?php

use App\Models\NotificationLog;
use App\Notifications\DailyReminder;
use App\Notifications\TestReminder;
use App\Services\NotificationLogger;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\WebPushChannel;

beforeEach(function (): void {
    $this->user = onboardedUser();
    $this->actingAs($this->user);
});

function pushReport(int $status, string $reason = 'OK'): MessageSentReport
{
    return new MessageSentReport(
        new PsrRequest('POST', 'https://web.push.apple.com/abc'),
        new PsrResponse($status),
        $status < 300,
        $reason,
    );
}

it('records the push service accepting a queued notification', function (): void {
    $notification = new DailyReminder('2026-10-14');
    resolve(NotificationLogger::class)->queued($this->user, $notification);

    event(new NotificationSent($this->user, $notification, WebPushChannel::class, [pushReport(201)]));

    expect($this->user->notificationLogs()->sole()->only(['type', 'status', 'push_status', 'push_host']))
        ->toBe(['type' => 'daily-reminder', 'status' => 'sent', 'push_status' => 201, 'push_host' => 'web.push.apple.com']);
});

it('records why the push service refused a notification', function (): void {
    $notification = new DailyReminder('2026-10-14');
    resolve(NotificationLogger::class)->queued($this->user, $notification);

    event(new NotificationSent($this->user, $notification, WebPushChannel::class, [pushReport(403, 'BadJwtToken')]));

    $log = $this->user->notificationLogs()->sole();

    expect($log->status)->toBe(NotificationLog::FAILED)
        ->and($log->push_status)->toBe(403)
        ->and($log->reason)->toContain('BadJwtToken');
});

it('records a notification that had no device to go to', function (): void {
    event(new NotificationSent($this->user, new DailyReminder('2026-10-14'), WebPushChannel::class, []));

    expect($this->user->notificationLogs()->sole()->only(['status', 'reason']))->toBe(['status' => 'failed', 'reason' => 'no push subscription']);
});

it('sends the test through the queue and shows the log in settings', function (): void {
    Notification::fake();
    $this->user->updatePushSubscription('https://web.push.apple.com/abc', 'key', 'token');

    Livewire::test('pages::settings.notifications')
        ->call('sendTestNotification')
        ->assertSee('data-test="notification-log"', false)
        ->assertSee('Test notification')
        ->assertSee('waiting to be sent');

    Notification::assertSentTo($this->user, TestReminder::class);
});

it('prunes log rows after 30 days', function (): void {
    NotificationLog::factory()->for($this->user)->create(['created_at' => now()->subDays(31)]);
    NotificationLog::factory()->for($this->user)->create();

    $this->artisan('model:prune', ['--model' => [NotificationLog::class]])->assertSuccessful();

    expect(NotificationLog::query()->count())->toBe(1);
});

it('explains failed pushes in plain words instead of the raw server answer', function (): void {
    foreach ([410 => 'push subscription has expired or is no longer valid', 403 => 'Forbidden', 500 => 'Internal Server Error'] as $status => $reason) {
        NotificationLog::factory()->for($this->user)->create(['status' => NotificationLog::FAILED, 'push_status' => $status, 'reason' => $reason]);
    }

    Livewire::test('pages::settings.notifications')
        ->assertSee('not delivered: the subscription of the device has expired, turn notifications on again')
        ->assertSee('not delivered: the push service refused it')
        ->assertSee('not delivered (500)')
        ->assertDontSee('Internal Server Error')
        ->assertDontSee('Forbidden');
});
