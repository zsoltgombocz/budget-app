<?php

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use App\Notifications\Invitation;
use App\Notifications\MagicLoginLink;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
});

it('sends guests to the passwordless sign-in', function (): void {
    $this->get('/admin')->assertRedirect(route('login'));
});

it('keeps regular users out of the admin and the monitoring', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin')->assertForbidden();
    expect(Gate::forUser($user)->allows('viewPulse'))->toBeFalse();
});

it('lets admins in', function (): void {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create(['name' => 'Bea Kovács']);

    $this->actingAs($admin)->get('/admin')->assertOk();
    $this->actingAs($admin)->get('/admin/users')->assertOk()->assertSee('Bea Kovács');
    expect(Gate::forUser($admin)->allows('viewPulse'))->toBeTrue();
});

it('shuts out disabled admins', function (): void {
    $this->actingAs(User::factory()->admin()->disabled()->create())->get('/admin')->assertForbidden();
});

it('invites a new user by email', function (): void {
    Notification::fake();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ManageUsers::class)
        ->callAction('invite', data: ['name' => 'Anna Nagy', 'email' => 'Anna@Example.com'])
        ->assertHasNoActionErrors();

    $anna = User::query()->where('email', 'anna@example.com')->sole();
    expect($anna->invited_at)->not->toBeNull()
        ->and($anna->email_verified_at)->toBeNull();

    Notification::assertSentTo($anna, Invitation::class, function (Invitation $invitation) use ($anna): bool {
        $mail = $invitation->toMail($anna);

        return $mail->actionUrl === route('magic-link.show', $invitation->token);
    });
});

it('does not invite an address twice', function (): void {
    $this->actingAs(User::factory()->admin()->create());
    User::factory()->create(['email' => 'anna@example.com']);

    Livewire::test(ManageUsers::class)
        ->callAction('invite', data: ['name' => 'Anna', 'email' => 'anna@example.com'])
        ->assertHasActionErrors(['email' => 'unique']);
});

it('signs the invited user in with the link from the invite', function (): void {
    Notification::fake();
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ManageUsers::class)->callAction('invite', data: ['name' => 'Anna', 'email' => 'anna@example.com']);
    $anna = User::query()->where('email', 'anna@example.com')->sole();

    $token = null;
    Notification::assertSentTo($anna, Invitation::class, function (Invitation $invitation) use (&$token): bool {
        $token = $invitation->token;

        return true;
    });

    auth()->logout();
    $this->post(route('magic-link.login', $token))->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($anna);
    expect($anna->refresh()->hasVerifiedEmail())->toBeTrue();
});

it('disables and enables a user', function (): void {
    $this->actingAs(User::factory()->admin()->create());
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

it('makes an admin from the command line', function (): void {
    $user = User::factory()->create(['email' => 'boss@example.com']);

    $this->artisan('budget:make-admin', ['email' => 'BOSS@example.com'])->assertSuccessful();

    expect($user->refresh()->is_admin)->toBeTrue();
});

it('invites a new admin from the command line when given a name', function (): void {
    Notification::fake();

    $this->artisan('budget:make-admin', ['email' => 'new@example.com'])->assertFailed();
    $this->artisan('budget:make-admin', ['email' => 'new@example.com', '--name' => 'New Admin'])->assertSuccessful();

    $admin = User::query()->where('email', 'new@example.com')->sole();
    expect($admin->is_admin)->toBeTrue();
    Notification::assertSentTo($admin, Invitation::class);
});
