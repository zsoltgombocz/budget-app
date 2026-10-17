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

it('has no back arrow on the settings tab itself', function (): void {
    $this->actingAs(onboardedUser());

    $this->get(route('settings'))->assertOk()->assertDontSee('data-test="settings-back"', false)->assertSee(route('changelog', ['honnan' => 'beallitasok']));
});

it('goes back with an arrow from What’s new: to settings when opened there, to Today otherwise', function (): void {
    $this->actingAs(onboardedUser());

    $this->get(route('changelog', ['honnan' => 'beallitasok']))->assertSee('<a href="'.route('settings').'"', false)->assertSee('data-test="settings-back"', false);
    $this->get(route('changelog'))->assertSee('<a href="'.route('dashboard').'"', false)->assertSee('data-test="settings-back"', false)->assertDontSee('>close<', false);
});

it('lists the time zones in words with Budapest first', function (): void {
    $this->actingAs(onboardedUser(['locale' => 'hu']));
    app()->setLocale('hu');

    $this->get(route('appearance.edit'))
        ->assertSeeInOrder(['Budapest (közép-európai idő)', 'Minden időzóna', 'Europe / Vienna'])
        ->assertDontSee('>Europe/Vienna<', false);
});

it('offers an Animations switch kept in the browser like the theme, applied before the first paint', function (): void {
    $this->actingAs(onboardedUser(['locale' => 'hu']));
    app()->setLocale('hu');

    $this->get(route('appearance.edit'))
        ->assertSeeInOrder(['Téma', 'Animációk', 'Nyelv'])
        ->assertSee('role="switch"', false)
        ->assertSee('data-test="motion-toggle"', false)
        ->assertSee('window.appMotion?.set(value)', false)
        // The head script sets the root attribute from the stored choice or the system's reduced motion.
        ->assertSee("localStorage.getItem('motion')", false)
        ->assertSee('prefers-reduced-motion: reduce', false);
});

it('keeps the danger colour to text and buttons, with a solid button only for the final confirmation', function (): void {
    $this->actingAs(onboardedUser());

    $this->get(route('settings'))
        ->assertSeeHtmlInOrder(['bg-danger/14 text-danger', 'logout', 'text-danger', 'Log out']);

    Livewire::test('pages::settings.delete-user-form')
        ->assertDontSee('bg-danger/8', false)
        ->assertSeeHtmlInOrder(['data-test="delete-user-button"', 'data-variant="danger"', 'data-test="confirm-delete-user-button"', 'data-variant="danger-solid"']);

    Livewire::test('pages::settings.budget')
        ->assertDontSee('bg-danger/8', false)
        ->assertSeeHtmlInOrder(['data-test="reset-budget"', 'data-variant="danger"', 'data-test="confirm-reset"', 'data-variant="danger-solid"']);
});

it('labels the period options in one short line and explains the chosen one below', function (): void {
    $this->actingAs(onboardedUser());

    Livewire::test('pages::settings.budget')
        ->set('periodMode', 'calendar')
        ->assertSeeHtmlInOrder(['data-test="period-payday"', 'From payday', 'data-test="period-calendar"', 'Calendar month', 'data-test="period-explanation"', 'The month runs from the 1st to the last day of the month.'])
        ->set('periodMode', 'payday')
        ->assertSee('The month runs from payday to the day before the next payday.');
});

it('shows a compact empty state for passkeys and for the notification log', function (): void {
    $this->actingAs(onboardedUser());

    Livewire::test('pages::settings.security')->assertSeeHtmlInOrder(['data-empty-state="compact"', 'No passkeys yet']);
    Livewire::test('pages::settings.notifications')->assertSeeHtmlInOrder(['data-test="notification-log-empty"', 'data-empty-state="compact"', 'No notifications yet']);
});
