<?php

use App\Enums\Plan;
use Livewire\Livewire;

it('shows the trial, what is in Plusz and the prices', function (): void {
    $user = onboardedUser();
    $this->actingAs($user);

    $this->get(route('subscription'))->assertOk()
        ->assertSee('Plusz trial')
        ->assertSee('What is in Plusz?')
        ->assertSee('data-test="prices"', false)
        ->assertSee('data-test="subscribe"', false)
        ->assertDontSee('data-test="cancel"', false);
});

it('does not start a checkout without a Stripe price', function (): void {
    config(['budget.stripe_prices.year' => null]);
    $this->actingAs(onboardedUser());

    Livewire::test('pages::settings.subscription')
        ->call('subscribe')
        ->assertDispatched('app-toast')
        ->assertNoRedirect();
});

it('offers cancelling a running subscription, and taking a cancellation back', function (): void {
    $user = onboardedUser();
    $this->actingAs($user);
    $subscription = $user->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_1', 'stripe_status' => 'active', 'stripe_price' => 'price_1', 'quantity' => 1]);

    $this->get(route('subscription'))->assertOk()
        ->assertSee('data-test="cancel"', false)
        ->assertDontSee('data-test="subscribe"', false);

    $subscription->update(['ends_at' => now()->addDays(20)]);
    $this->actingAs($user->fresh());

    $this->get(route('subscription'))
        ->assertSee('data-test="resume"', false)
        ->assertDontSee('data-test="cancel"', false);

    expect($user->fresh()->currentPlan())->toBe(Plan::Plus);
});
