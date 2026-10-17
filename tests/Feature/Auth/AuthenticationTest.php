<?php

use App\Actions\Auth\SendMagicLink;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use App\Notifications\NoAccountForEmail;
use Illuminate\Notifications\AnonymousNotifiable;
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

test('unknown addresses get the same answer and a register-first email instead of a code', function (): void {
    Notification::fake();

    Livewire::test('auth.magic-link-form')
        ->set('email', 'Nobody@example.com')
        ->call('send')
        ->assertSet('sentTo', 'nobody@example.com');

    Notification::assertSentOnDemand(NoAccountForEmail::class, fn (NoAccountForEmail $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'nobody@example.com');
    Notification::assertNotSentTo(new AnonymousNotifiable, MagicLoginLink::class);
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

    expect($html)->toContain('482915')->toContain(route('magic-link.show', 'token-123'));
});

test('once the code is sent the page steps aside for the code field', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    $this->get(route('login'))
        ->assertOk()
        ->assertSeeInOrder(['x-on:magic-link-step.window', 'x-show="! codeSent"', 'data-test="login-intro"', 'data-test="magic-link-email"'], false);

    Livewire::test('auth.magic-link-form')
        ->set('email', $user->email)
        ->call('send')
        ->assertDispatched('magic-link-step', sent: true)
        ->assertSeeHtmlInOrder(['data-test="magic-link-sent"', 'Check your inbox', 'data-test="login-code"', 'data-test="login-code-submit"', 'data-test="magic-link-note"', $user->email])
        ->call('again')
        ->assertDispatched('magic-link-step', sent: false)
        ->assertSee('data-test="magic-link-email"', false);
});

it('names the address with the right Hungarian article and hides the invite note while the code is typed', function (): void {
    Notification::fake();
    app()->setLocale('hu');
    User::factory()->create(['email' => 'anna@example.com']);

    Livewire::test('auth.magic-link-form')
        ->set('email', 'anna@example.com')
        ->call('send')
        ->assertSee('Ha az anna@example.com címhez tartozik fiók, elküldtük rá a 6 jegyű kódot.');

    config(['budget.registration_open' => false]);
    $this->get(route('login'))->assertSee('x-show="! codeSent" data-test="invite-only"', false);
});

test('the email comes first, the passkey below it as the quick way back in', function (): void {
    app()->setLocale('hu');

    $this->get(route('login'))
        ->assertSeeInOrder(['data-test="magic-link-email"', 'data-test="login-passkey"'], false)
        ->assertSee('placeholder="nev@pelda.hu"', false)
        ->assertSee('novalidate', false)
        ->assertSee('Gyors belépés Face ID-val vagy ujjlenyomattal');
});

test('a mistyped address gets our own message', function (): void {
    Livewire::test('auth.magic-link-form')
        ->set('email', 'anna-at-example')
        ->call('send')
        ->assertHasErrors('email')
        ->assertSee('This does not look like an email address. It looks like this: name@example.com');
});
