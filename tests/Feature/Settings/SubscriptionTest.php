<?php

use App\Enums\Plan;
use App\Notifications\SubscriptionStarted;
use Illuminate\Support\Facades\Notification;
use Laravel\Cashier\Events\WebhookHandled;
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

it('shows the subscription summary before paying and offers withdrawal only in its first days', function (): void {
    $user = onboardedUser();
    $this->actingAs($user);

    $this->get(route('subscription'))
        ->assertSee('data-test="subscription-summary"', false)
        ->assertSee('Subscribe – with an obligation to pay');

    $user->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_9', 'stripe_status' => 'active', 'stripe_price' => 'price_1', 'quantity' => 1]);
    $this->actingAs($user->fresh());
    $this->get(route('subscription'))->assertSee('data-test="withdraw"', false);

    $this->travel(15)->days();
    $this->actingAs($user->fresh());
    $this->get(route('subscription'))->assertDontSee('data-test="withdraw"', false);
});

it('sends the confirmation e-mail when Stripe reports a new subscription', function (): void {
    Notification::fake();
    $user = onboardedUser();
    $user->forceFill(['stripe_id' => 'cus_123'])->save();

    event(new WebhookHandled(['type' => 'customer.subscription.created', 'data' => ['object' => [
        'customer' => 'cus_123',
        'items' => ['data' => [['price' => ['unit_amount' => 399000, 'currency' => 'huf', 'recurring' => ['interval' => 'month']]]]],
    ]]]));

    Notification::assertSentTo($user, SubscriptionStarted::class);
});
