<?php

namespace App\Listeners;

use App\Models\User;
use App\Notifications\SubscriptionStarted;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;

/**
 * When Stripe reports a new subscription, the user gets the confirmation e-mail.
 */
class SendSubscriptionConfirmation
{
    public function handle(WebhookHandled $event): void
    {
        if (($event->payload['type'] ?? null) !== 'customer.subscription.created') {
            return;
        }

        $customer = data_get($event->payload, 'data.object.customer');
        $user = is_string($customer) ? User::query()->where('stripe_id', $customer)->first() : null;

        if (! $user instanceof User) {
            return;
        }

        $interval = data_get($event->payload, 'data.object.items.data.0.price.recurring.interval');
        $amount = data_get($event->payload, 'data.object.items.data.0.price.unit_amount');
        $currency = data_get($event->payload, 'data.object.items.data.0.price.currency');
        $trialEndsAt = data_get($event->payload, 'data.object.trial_end');
        $trialEnd = is_int($trialEndsAt) ? CarbonImmutable::createFromTimestamp($trialEndsAt) : null;

        $user->notify(new SubscriptionStarted(
            price: Cashier::formatAmount(is_int($amount) ? $amount : 0, is_string($currency) ? $currency : null).' '.($interval === 'year' ? __('/ year') : __('/ month')),
            yearly: $interval === 'year',
            firstCharge: $trialEnd instanceof CarbonImmutable && $trialEnd->isFuture() ? $trialEnd : null,
        ));
    }
}
