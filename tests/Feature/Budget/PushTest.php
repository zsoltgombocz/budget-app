<?php

use App\Models\DayMark;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

it('stores and removes the device push subscription', function (): void {
    $user = onboardedUser();
    $this->actingAs($user);

    $this->postJson(route('push-subscriptions.store'), [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
        'keys' => ['p256dh' => 'public-key', 'auth' => 'auth-token'],
    ])->assertOk();

    expect($user->pushSubscriptions()->count())->toBe(1);

    $this->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'])->assertOk();

    expect($user->pushSubscriptions()->count())->toBe(0);
});

it('rejects invalid subscriptions', function (): void {
    $this->actingAs(onboardedUser());

    $this->postJson(route('push-subscriptions.store'), ['endpoint' => 'http://insecure.example.com'])
        ->assertUnprocessable();
});

it('marks a no-spend day from the notification button with a signed url', function (): void {
    $user = onboardedUser();
    $url = URL::temporarySignedRoute('push.no-spend', now()->addDay(), ['user' => $user->id, 'date' => '2026-10-14']);

    $this->post($url)->assertNoContent();
    $this->post($url)->assertNoContent();

    expect(DayMark::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('refuses an unsigned no-spend request', function (): void {
    $user = onboardedUser();

    $this->post(route('push.no-spend', ['user' => $user->id, 'date' => '2026-10-14']))->assertForbidden();
});

it('serves a valid web app manifest', function (): void {
    $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);

    expect($manifest)->toMatchArray(['start_url' => '/ma', 'display' => 'standalone', 'scope' => '/'])
        ->and(collect($manifest['icons'])->pluck('sizes')->unique()->values()->all())->toBe(['192x192', '512x512'])
        ->and(collect($manifest['icons'])->pluck('purpose')->unique()->values()->all())->toBe(['any', 'maskable']);

    foreach ($manifest['icons'] as $icon) {
        expect(public_path(ltrim($icon['src'], '/')))->toBeFile();
        [$width, $height] = getimagesize(public_path(ltrim($icon['src'], '/')));
        expect("{$width}x{$height}")->toBe($icon['sizes']);
    }
});

it('links the manifest and service worker assets', function (): void {
    $this->actingAs(onboardedUser());

    $this->get(route('dashboard'))
        ->assertSee('rel="manifest"', false)
        ->assertSee('name="vapid-public-key"', false);

    expect(public_path('sw.js'))->toBeFile()
        ->and(public_path('offline.html'))->toBeFile();
});

it('renders the device-aware reminder prompt', function (): void {
    $this->actingAs(onboardedUser());

    $this->get(route('dashboard'))->assertSee('data-test="push-prompt"', false);
});

it('stores the reminder choices from the notification onboarding', function (): void {
    $user = onboardedUser();
    $this->actingAs($user);

    Livewire::test('pages::notifications')
        ->call('pick', '21:30')
        ->set('dueReminders', false)
        ->call('finish', true)
        ->assertRedirect(route('dashboard'));

    $settings = $user->settings()->refresh();

    expect($settings->reminder_time)->toStartWith('21:30')
        ->and($settings->due_reminder_enabled)->toBeFalse()
        ->and($settings->notifications_onboarded_at)->not->toBeNull();
});
