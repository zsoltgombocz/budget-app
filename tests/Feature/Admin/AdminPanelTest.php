<?php

use App\Filament\Pages\Auth\LinkSignIn;
use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\Admins\Pages\ManageAdmins;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\Admin;
use App\Models\User;
use App\Notifications\AdminSignInLink;
use App\Notifications\Invitation;
use App\Notifications\MagicLoginLink;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('sends guests to the admin login page', function (): void {
    $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
    $this->get('/pulse')->assertRedirect(route('filament.admin.auth.login'));
    $this->get(route('filament.admin.auth.login'))->assertOk()->assertDontSee('type="password"', false);
});

it('keeps app users out of the admin and the monitoring', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
    expect(Gate::forUser($user)->allows('viewPulse'))->toBeFalse();
});

it('lets admins in', function (): void {
    $admin = Admin::factory()->create();
    User::factory()->create(['name' => 'Bea Kovács']);

    $this->actingAs($admin, 'admin')->get('/admin')->assertOk();
    $this->actingAs($admin, 'admin')->get('/admin/users')->assertOk()->assertSee('Bea Kovács');
    expect(Gate::forUser($admin)->allows('viewPulse'))->toBeTrue();
});

it('signs an admin in with the emailed code', function (): void {
    Notification::fake();
    $admin = Admin::factory()->create(['email' => 'boss@example.com']);

    $login = Livewire::test(Login::class)
        ->fillForm(['email' => 'Boss@example.com'])
        ->call('authenticate')
        ->assertSet('sentTo', 'boss@example.com');

    $code = null;
    Notification::assertSentTo($admin, AdminSignInLink::class, function (AdminSignInLink $notification) use (&$code): bool {
        $code = $notification->code;

        return true;
    });

    $login->fillForm(['code' => '000000'])->call('authenticate')->assertHasFormErrors(['code']);
    $this->assertGuest('admin');

    $login->fillForm(['code' => $code])->call('authenticate')->assertHasNoFormErrors();
    $this->assertAuthenticatedAs($admin, 'admin');
    $this->assertGuest('web');
    expect($admin->refresh()->last_login_at)->not->toBeNull();
});

it('signs an admin in with the button in the email, once', function (): void {
    Notification::fake();
    $admin = Admin::factory()->create();

    Livewire::test(Login::class)->fillForm(['email' => $admin->email])->call('authenticate');

    $token = null;
    Notification::assertSentTo($admin, AdminSignInLink::class, function (AdminSignInLink $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    $this->get(route('filament.admin.auth.link', $token))->assertOk();
    $this->assertGuest('admin');

    Livewire::test(LinkSignIn::class, ['token' => $token])->call('signIn')->assertRedirect(Filament::getUrl());
    $this->assertAuthenticatedAs($admin, 'admin');

    auth('admin')->logout();
    Livewire::test(LinkSignIn::class, ['token' => $token])->call('signIn');
    $this->assertGuest('admin');
});

it('shows who finished the setup wizard', function (): void {
    $this->actingAs(Admin::factory()->create(), 'admin');
    $done = onboardedUser();
    $done->settings()->update(['onboarded_at' => now()]);
    $started = User::factory()->create();
    $started->settings();

    Livewire::test(ManageUsers::class)
        ->assertTableColumnStateSet('onboarded', true, $done)
        ->assertTableColumnStateSet('onboarded', false, $started);
});

it('sends nothing to an address that is not an admin', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email])
        ->call('authenticate')
        ->assertSet('sentTo', strtolower($user->email));

    Notification::assertNothingSent();
});

it('adds an admin from the panel', function (): void {
    $this->actingAs(Admin::factory()->create(), 'admin');

    Livewire::test(ManageAdmins::class)
        ->callAction('create', data: ['name' => 'Second Admin', 'email' => 'second@example.com'])
        ->assertHasNoActionErrors();

    expect(Admin::query()->where('email', 'second@example.com')->exists())->toBeTrue();
});

it('invites a new user by email', function (): void {
    Notification::fake();
    $this->actingAs(Admin::factory()->create(), 'admin');

    Livewire::test(ManageUsers::class)
        ->callAction('invite', data: ['name' => 'Anna Nagy', 'email' => 'Anna@Example.com'])
        ->assertHasNoActionErrors();

    $anna = User::query()->where('email', 'anna@example.com')->sole();
    expect($anna->invited_at)->not->toBeNull()
        ->and($anna->email_verified_at)->toBeNull();

    Notification::assertSentTo($anna, Invitation::class, fn (Invitation $invitation): bool => $invitation->toMail($anna)->actionUrl === route('magic-link.show', $invitation->token));
});

it('does not invite an address twice', function (): void {
    $this->actingAs(Admin::factory()->create(), 'admin');
    User::factory()->create(['email' => 'anna@example.com']);

    Livewire::test(ManageUsers::class)
        ->callAction('invite', data: ['name' => 'Anna', 'email' => 'anna@example.com'])
        ->assertHasActionErrors(['email' => 'unique']);
});

it('signs the invited user in with the link from the invite', function (): void {
    Notification::fake();
    $this->actingAs(Admin::factory()->create(), 'admin');

    Livewire::test(ManageUsers::class)->callAction('invite', data: ['name' => 'Anna', 'email' => 'anna@example.com']);
    $anna = User::query()->where('email', 'anna@example.com')->sole();

    $token = null;
    Notification::assertSentTo($anna, Invitation::class, function (Invitation $invitation) use (&$token): bool {
        $token = $invitation->token;

        return true;
    });

    // The invite is opened in the app, not in the admin session.
    auth('admin')->logout();
    auth()->shouldUse('web');

    $this->post(route('magic-link.login', $token))->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($anna, 'web');
    expect($anna->refresh()->hasVerifiedEmail())->toBeTrue();
});

it('disables and enables a user', function (): void {
    $this->actingAs(Admin::factory()->create(), 'admin');
    $user = User::factory()->create();

    Livewire::test(ManageUsers::class)->callTableAction('disable', $user);
    expect($user->refresh()->isDisabled())->toBeTrue();

    Livewire::test(ManageUsers::class)->callTableAction('enable', $user);
    expect($user->refresh()->isDisabled())->toBeFalse();
});

it('signs a disabled user out and sends no sign-in code', function (): void {
    Notification::fake();
    $user = User::factory()->disabled()->create();

    $this->actingAs($user)->get(route('settings'))->assertRedirect(route('login'));
    $this->assertGuest();

    Livewire::test('auth.magic-link-form')->set('email', $user->email)->call('send');
    Notification::assertNotSentTo($user, MagicLoginLink::class);
});

it('remembers when a user was last seen', function (): void {
    $user = onboardedUser();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect($user->refresh()->last_seen_at)->not->toBeNull();
});

it('adds an admin from the command line', function (): void {
    $this->artisan('budget:make-admin', ['email' => 'BOSS@example.com', '--name' => 'Boss'])->assertSuccessful();
    $this->artisan('budget:make-admin', ['email' => 'boss@example.com'])->assertSuccessful();

    expect(Admin::query()->where('email', 'boss@example.com')->sole()->name)->toBe('Boss');
});
