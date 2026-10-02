<?php

use App\Models\User;
use App\Notifications\MagicLoginLink;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function (): void {
    config(['budget.registration_open' => true]);
});

test('registration is closed when the app is invite only', function (): void {
    config(['budget.registration_open' => false]);

    $this->get(route('register'))->assertNotFound();
    $this->get(route('login'))->assertDontSee(route('register'));

    Livewire::test('auth.magic-link-form', ['register' => true])
        ->set('name', 'Anna')
        ->set('email', 'anna@example.com')
        ->call('send')
        ->assertNotFound();

    expect(User::query()->count())->toBe(0);
});

test('registration screen can be rendered', function (): void {
    $this->get(route('register'))->assertOk()->assertDontSee('type="password"', false);
});

test('registering creates the account and emails a sign-in link', function (): void {
    Notification::fake();

    Livewire::test('auth.magic-link-form', ['register' => true])
        ->set('name', 'Test User')
        ->set('email', 'Test@Example.com')
        ->call('send')
        ->assertHasNoErrors();

    $user = User::query()->where('email', 'test@example.com')->sole();

    expect($user->name)->toBe('Test User')
        ->and($user->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user, MagicLoginLink::class);
});

test('registering an existing address only sends a sign-in link', function (): void {
    Notification::fake();
    $user = User::factory()->create(['name' => 'Original']);

    Livewire::test('auth.magic-link-form', ['register' => true])
        ->set('name', 'Someone Else')
        ->set('email', $user->email)
        ->call('send');

    expect(User::query()->count())->toBe(1)->and($user->refresh()->name)->toBe('Original');
    Notification::assertSentTo($user, MagicLoginLink::class);
});

test('a name is required to register', function (): void {
    Livewire::test('auth.magic-link-form', ['register' => true])
        ->set('email', 'new@example.com')
        ->call('send')
        ->assertHasErrors('name');
});
