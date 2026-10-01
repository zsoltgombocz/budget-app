<?php

use App\Actions\Auth\SendMagicLink;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('login screen offers a magic link and a passkey, no password', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('data-test="magic-link-email"', false)
        ->assertDontSee('type="password"', false);
});

test('a sign-in link is emailed to existing users', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    Livewire::test('auth.magic-link-form')
        ->set('email', strtoupper($user->email))
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('sentTo', strtolower($user->email));

    Notification::assertSentTo($user, MagicLoginLink::class);
});

test('unknown addresses get the same answer but no email', function (): void {
    Notification::fake();

    Livewire::test('auth.magic-link-form')
        ->set('email', 'nobody@example.com')
        ->call('send')
        ->assertSet('sentTo', 'nobody@example.com');

    Notification::assertNothingSent();
    expect(User::query()->count())->toBe(0);
});

test('the link signs in once, after confirming on the page', function (): void {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    resolve(SendMagicLink::class)->handle($user->email);

    $token = null;
    Notification::assertSentTo($user, MagicLoginLink::class, function (MagicLoginLink $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    $this->get(route('magic-link.show', $token))->assertOk()->assertSee('data-test="magic-link-confirm"', false);
    $this->assertGuest();

    $this->post(route('magic-link.login', $token))->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();

    auth()->logout();
    $this->post(route('magic-link.login', $token))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('an unknown or expired link does not sign in', function (): void {
    $this->post(route('magic-link.login', 'not-a-real-token'))->assertRedirect(route('login'))->assertSessionHas('status');

    $this->assertGuest();
});

test('password sign-in is switched off', function (): void {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->assertGuest();
});

test('sending links is rate limited', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $component = Livewire::test('auth.magic-link-form')->set('email', $user->email);

    foreach (range(1, 5) as $attempt) {
        $component->call('send')->call('again')->set('email', $user->email);
    }

    $component->call('send')->assertHasErrors('email');
});

test('users can logout', function (): void {
    $this->actingAs(onboardedUser());

    Livewire::test('pages::settings.index')->call('logout')->assertRedirect('/');

    $this->assertGuest();
});

test('the emailed code signs in inside the app', function (): void {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    $component = Livewire::test('auth.magic-link-form')->set('email', $user->email)->call('send');

    $code = null;
    Notification::assertSentTo($user, MagicLoginLink::class, function (MagicLoginLink $notification) use (&$code): bool {
        $code = $notification->code;

        return true;
    });

    expect($code)->toMatch('/^\d{6}$/');

    $component->set('code', substr($code, 0, 3).' '.substr($code, 3))->call('verify')->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();
});

test('a wrong code does not sign in and the code dies after too many attempts', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $component = Livewire::test('auth.magic-link-form')->set('email', $user->email)->call('send');

    $code = null;
    Notification::assertSentTo($user, MagicLoginLink::class, function (MagicLoginLink $notification) use (&$code): bool {
        $code = $notification->code;

        return true;
    });

    $wrong = $code === '111111' ? '222222' : '111111';

    foreach (range(1, SendMagicLink::MAX_ATTEMPTS) as $attempt) {
        $component->set('code', $wrong)->call('verify')->assertHasErrors('code');
    }

    $component->set('code', $code)->call('verify')->assertHasErrors('code');
    $this->assertGuest();
});

test('the sign-in email shows the code and the link', function (): void {
    $user = User::factory()->create();
    app()->setLocale('hu');

    $html = (string) new MagicLoginLink('token-123', '482915')->toMail($user)->render();

    expect($html)->toContain('482 915')->toContain(route('magic-link.show', 'token-123'));
});
