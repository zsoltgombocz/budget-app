<?php

use App\Notifications\NewVersionAvailable;
use App\Support\Changelog;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function (): void {
    Notification::fake();
    $this->user = onboardedUser();
    $this->user->updatePushSubscription('https://web.push.apple.com/abc', 'key', 'token');
});

it('announces the current version once to users with notifications', function (): void {
    $this->artisan('budget:announce-version')->assertSuccessful();
    $this->artisan('budget:announce-version')->assertSuccessful();

    Notification::assertSentToTimes($this->user, NewVersionAvailable::class, 1);

    expect($this->user->settings()->refresh()->notified_version)->toBe(Changelog::version())
        ->and($this->user->notificationLogs()->sole()->type)->toBe('new-version-available');
});

it('respects the new version setting and does not announce the skipped version later', function (): void {
    $this->user->settings()->update(['version_reminder_enabled' => false]);

    $this->artisan('budget:announce-version')->assertSuccessful();
    $this->user->settings()->update(['version_reminder_enabled' => true]);
    $this->artisan('budget:announce-version')->assertSuccessful();

    Notification::assertNothingSentTo($this->user);
});

it('tells what is new and opens What is new', function (): void {
    $payload = new NewVersionAvailable(Changelog::version())->toWebPush($this->user, new NewVersionAvailable(Changelog::version()))->toArray();

    expect($payload['title'])->toContain(Changelog::version())
        ->and($payload['body'])->toStartWith(mb_substr(Changelog::changes(Changelog::all()[0], 'en')[0], 0, 20))
        ->and($payload['data']['url'])->toBe(route('changelog', absolute: false));
});

it('can be switched off in the notification settings', function (): void {
    $this->actingAs($this->user);

    Livewire::test('pages::settings.notifications')->set('versionReminderEnabled', false);

    expect($this->user->settings()->refresh()->version_reminder_enabled)->toBeFalse();
});
