<?php

use Livewire\Livewire;

test('security settings open without a password and list passkeys', function (): void {
    $user = onboardedUser();
    $this->actingAs($user);

    $this->get(route('security.edit'))
        ->assertOk()
        ->assertSee('data-test="passkeys"', false)
        ->assertSee('data-test="settings-back"', false)
        ->assertDontSee('type="password"', false);
});

test('a passkey can be removed', function (): void {
    $user = onboardedUser();
    $this->actingAs($user);
    $passkey = $user->passkeys()->create([
        'name' => 'iPhone',
        'credential_id' => 'credential-1',
        'credential' => ['id' => 'credential-1'],
    ]);

    Livewire::test('pages::settings.security')->call('deletePasskey', $passkey->id)->assertSet('passkeys', []);
});

test('another user\'s passkey cannot be removed', function (): void {
    $this->actingAs(onboardedUser());
    $foreign = onboardedUser()->passkeys()->create(['name' => 'X', 'credential_id' => 'credential-2', 'credential' => ['id' => 'credential-2']]);

    Livewire::test('pages::settings.security')->call('deletePasskey', $foreign->id)->assertNotFound();
});
