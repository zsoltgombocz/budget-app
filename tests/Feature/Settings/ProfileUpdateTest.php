<?php

use App\Models\User;
use Livewire\Livewire;

test('profile page is displayed', function (): void {
    $this->actingAs($user = User::factory()->create());

    $this->get(route('profile.edit'))->assertOk();
});

test('profile information can be updated', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toEqual('Test User');
    expect($user->email)->toEqual('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when email address is unchanged', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', $user->email)
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account by typing their email', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.delete-user-form')
        ->set('confirmation', strtoupper($user->email))
        ->call('deleteUser')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull();
    $this->assertGuest();
});

test('the email must match to delete the account', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.delete-user-form')
        ->set('confirmation', 'someone@else.com')
        ->call('deleteUser')
        ->assertHasErrors(['confirmation']);

    expect($user->fresh())->not->toBeNull();
});
