<?php

use Livewire\Livewire;

it('opens a settings menu that links every section', function (): void {
    $this->actingAs(onboardedUser());

    $response = $this->get(route('settings'))->assertOk();

    foreach (['budget.edit', 'notifications.edit', 'appearance.edit', 'profile.edit', 'security.edit', 'install', 'changelog'] as $route) {
        $response->assertSee(route($route));
    }
});

it('gives every section a way back to the menu', function (string $route): void {
    $this->actingAs(onboardedUser());

    $this->get(route($route))->assertOk()->assertSee('data-test="settings-back"', false)->assertSee(route('settings'));
})->with(['budget.edit', 'notifications.edit', 'appearance.edit', 'profile.edit', 'security.edit']);

it('saves notification settings right away', function (): void {
    $user = onboardedUser();
    $this->actingAs($user);

    Livewire::test('pages::settings.notifications')
        ->set('reminderTime', '21:30')
        ->set('dueReminderEnabled', false);

    $settings = $user->settings()->refresh();

    expect($settings->reminder_time)->toStartWith('21:30')
        ->and($settings->due_reminder_enabled)->toBeFalse();
});
