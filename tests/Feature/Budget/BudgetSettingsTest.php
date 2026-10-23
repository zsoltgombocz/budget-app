<?php

use App\Enums\Currency;
use App\Enums\PeriodMode;
use Livewire\Livewire;

it('updates the period right away', function (): void {
    $user = onboardedUser();
    $this->actingAs($user);

    $this->get(route('budget.edit'))->assertOk()->assertSee(route('month'), false);

    Livewire::test('pages::settings.budget')
        ->set('periodMode', 'payday')
        ->set('paydayDay', 10)
        ->assertHasNoErrors()
        ->assertNotDispatched('app-toast');

    $settings = $user->settings()->refresh();

    expect($settings->period_mode)->toBe(PeriodMode::Payday)
        ->and($settings->payday_day)->toBe(10)
        ->and($settings->currency)->toBe(Currency::HUF);
});

it('saves language and time zone from the appearance page right away', function (): void {
    $user = onboardedUser(['locale' => 'hu']);
    $this->actingAs($user);

    Livewire::test('pages::settings.appearance')
        ->set('timezone', 'Europe/London')
        ->assertHasNoErrors()
        ->set('locale', 'en')
        ->assertRedirect(route('appearance.edit'));

    $settings = $user->settings()->refresh();

    expect($settings->timezone)->toBe('Europe/London')
        ->and($settings->locale)->toBe('en');
});
