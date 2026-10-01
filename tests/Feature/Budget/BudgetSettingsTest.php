<?php

use App\Enums\PeriodMode;
use Livewire\Livewire;

it('updates the budget settings', function (): void {
    $user = onboardedUser();
    $this->actingAs($user);
    $account = $user->accounts()->create(['name' => 'Broker', 'type' => 'investment', 'currency' => 'HUF']);

    $this->get(route('budget.edit'))->assertOk();

    Livewire::test('pages::settings.budget')
        ->set('periodMode', 'payday')
        ->set('paydayDay', 10)
        ->set('locale', 'en')
        ->set('surplusTarget', 'account:'.$account->id)
        ->call('save')
        ->assertHasNoErrors();

    $settings = $user->settings()->refresh();

    expect($settings->period_mode)->toBe(PeriodMode::Payday)
        ->and($settings->payday_day)->toBe(10)
        ->and($settings->locale)->toBe('en')
        ->and($settings->surplus_account_id)->toBe($account->id);
});

it('ignores another user\'s surplus target', function (): void {
    $this->actingAs(onboardedUser());
    $foreign = onboardedUser()->accounts()->create(['name' => 'X', 'type' => 'investment', 'currency' => 'HUF']);

    Livewire::test('pages::settings.budget')
        ->set('surplusTarget', 'account:'.$foreign->id)
        ->call('save');

    expect(auth()->user()->settings()->refresh()->surplus_account_id)->toBeNull();
});
