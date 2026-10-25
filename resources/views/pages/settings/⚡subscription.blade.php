<?php

use App\Enums\Plan;
use App\Notifications\SubscriptionCancelled;
use App\Notifications\SubscriptionWithdrawn;
use App\Models\User;
use App\Services\Plans;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Invoice;
use Laravel\Cashier\Subscription;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * "Előfizetés": Alap is free, Plusz is paid through Stripe Checkout. Shows the plan, the next
 * payment, the invoices, and cancelling (Plusz stays until the end of the paid time).
 */
new #[Title('Subscription')] class extends Component {
    public string $interval = 'year';

    public function mount(): void
    {
        if (request()->query('fizetes') === 'ok') {
            $this->dispatch('app-toast', title: __('Thank you!'), subtitle: __('Plusz turns on in a few seconds.'), icon: 'workspace_premium');
        }
    }

    /**
     * Off to Stripe Checkout with the chosen price; Stripe sends the subscription back by webhook.
     */
    public function subscribe(): void
    {
        $price = config('budget.stripe_prices.'.($this->interval === 'month' ? 'month' : 'year'));

        if (! is_string($price) || $price === '' || $this->subscription !== null) {
            $this->dispatch('app-toast', title: __('Payment is not available right now.'), icon: 'error');

            return;
        }

        try {
            if ($this->hasStripeSubscription()) {
                $this->dispatch('app-toast', title: __('You already have a subscription.'), subtitle: __('It shows here in a few seconds.'), icon: 'info');

                return;
            }

            $builder = $this->user()->newSubscription(Plans::SUBSCRIPTION, $price);
            $trialEnd = $this->trialCarriesOver();

            // Buying during the trial keeps its days: the first charge is when the trial ends.
            if ($trialEnd !== null) {
                $builder->trialUntil($trialEnd);
            }

            $checkout = $builder->checkout([
                'success_url' => route('subscription', ['fizetes' => 'ok']),
                'cancel_url' => route('subscription'),
                'locale' => 'hu',
                'billing_address_collection' => 'required',
                'custom_text' => ['submit' => ['message' => __('By paying you order Plusz with an obligation to pay and accept the Terms (:url). It renews automatically until you cancel it; within :days days you can withdraw with a full refund.', ['url' => route('legal', 'aszf'), 'days' => config('budget.withdrawal_days')])]],
            ]);
        } catch (Throwable $exception) {
            Log::warning('Stripe checkout failed', ['user' => $this->user()->id, 'error' => $exception->getMessage()]);
            $this->dispatch('app-toast', title: __('Payment is not available right now.'), icon: 'error');

            return;
        }

        $this->redirect($checkout->url);
    }

    /**
     * Cancel at the end of the paid time; until then Plusz stays and it can be taken back.
     */
    public function cancel(): void
    {
        if ($this->changeSubscription(fn (Subscription $subscription) => $subscription->cancel(), __('Plusz cancelled'))) {
            $this->user()->notify(new SubscriptionCancelled($this->user()->subscription(Plans::SUBSCRIPTION)?->ends_at));
        }
    }

    /**
     * "Elállás": within the withdrawal days the subscription ends now and what was paid comes back.
     */
    public function withdraw(): void
    {
        $subscription = $this->subscription;
        abort_unless($subscription instanceof Subscription && $this->canWithdraw, 403);

        try {
            $refund = $this->refundLastCharge();
            $subscription->cancelNow();
        } catch (Throwable $exception) {
            Log::warning('Stripe withdrawal failed', ['user' => $this->user()->id, 'error' => $exception->getMessage()]);
            $this->dispatch('app-toast', title: __('Payment is not available right now.'), subtitle: __('Write to us and we sort it out: :email', ['email' => config('budget.operator.email')]), icon: 'error');

            return;
        }

        $this->user()->notify(new SubscriptionWithdrawn(now(), $refund));
        unset($this->subscription, $this->nextPayment, $this->canWithdraw);
        $this->dispatch('app-toast', title: __('Withdrawal done'), subtitle: $refund !== null ? __(':amount comes back to your card.', ['amount' => $refund]) : null);
    }

    public function resume(): void
    {
        $this->changeSubscription(fn (Subscription $subscription) => $subscription->resume(), __('Plusz stays'));
    }

    /**
     * Stripe's own page for the card and the billing details.
     */
    public function billingPortal(): void
    {
        try {
            $this->redirect($this->user()->billingPortalUrl(route('subscription')));
        } catch (Throwable $exception) {
            Log::warning('Stripe billing portal failed', ['user' => $this->user()->id, 'error' => $exception->getMessage()]);
            $this->dispatch('app-toast', title: __('Payment is not available right now.'), icon: 'error');
        }
    }

    /**
     * Withdrawal is offered for the first days after the subscription started.
     */
    #[Computed]
    public function canWithdraw(): bool
    {
        $subscription = $this->subscription;

        return $subscription instanceof Subscription && ! $subscription->onGracePeriod()
            && $this->withdrawalEndsAt($subscription)?->isFuture() === true;
    }

    /**
     * The withdrawal days run from the start of the contract, or from the first charge when it was
     * bought during the trial (the more generous of the two).
     */
    public function withdrawalEndsAt(Subscription $subscription): ?\Carbon\CarbonInterface
    {
        $start = $subscription->trial_ends_at !== null && $subscription->created_at !== null && $subscription->trial_ends_at->greaterThan($subscription->created_at)
            ? $subscription->trial_ends_at
            : $subscription->created_at;

        return $start?->copy()->addDays(config()->integer('budget.withdrawal_days'));
    }

    #[Computed]
    public function subscription(): ?Subscription
    {
        $subscription = $this->user()->subscription(Plans::SUBSCRIPTION);

        return $subscription !== null && $subscription->valid() ? $subscription : null;
    }

    /**
     * The next payment of a running subscription: its date and amount, null when it will not renew.
     *
     * @return array{date: \Carbon\CarbonInterface, amount: string}|null
     */
    #[Computed]
    public function nextPayment(): ?array
    {
        if ($this->subscription === null || $this->subscription->onGracePeriod()) {
            return null;
        }

        try {
            $invoice = $this->subscription->upcomingInvoice();
        } catch (Throwable) {
            return null;
        }

        return $invoice instanceof Invoice ? ['date' => $invoice->date(), 'amount' => $invoice->total()] : null;
    }

    /**
     * @return list<array{date: \Carbon\CarbonInterface, amount: string, paid: bool, url: string|null}>
     */
    #[Computed]
    public function invoices(): array
    {
        if (! $this->user()->hasStripeId()) {
            return [];
        }

        try {
            return $this->user()->invoices()->map(fn (Invoice $invoice): array => [
                'date' => $invoice->date(),
                'amount' => $invoice->total(),
                'paid' => $invoice->isPaid(),
                'url' => $invoice->asStripeInvoice()->invoice_pdf ?? $invoice->asStripeInvoice()->hosted_invoice_url ?? null,
            ])->values()->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The end of a running trial, when it is far enough ahead for Stripe (at least two days).
     */
    private function trialCarriesOver(): ?\Carbon\CarbonImmutable
    {
        if ($this->user()->currentPlan() !== Plan::PlusTrial) {
            return null;
        }

        $end = resolve(Plans::class)->trialEndsAt($this->user());

        return $end->greaterThan(now()->addDays(2)) ? $end : null;
    }

    /**
     * Refunds the latest successful card payment of the customer; null when nothing was paid yet.
     */
    private function refundLastCharge(): ?string
    {
        if (! $this->user()->hasStripeId()) {
            return null;
        }

        $charges = $this->user()->stripe()->charges->all(['customer' => $this->user()->stripe_id, 'limit' => 1]);
        $charge = $charges->data[0] ?? null;

        if ($charge === null || ! $charge->paid || $charge->refunded) {
            return null;
        }

        $this->user()->stripe()->refunds->create(['charge' => $charge->id, 'reason' => 'requested_by_customer']);

        return \Laravel\Cashier\Cashier::formatAmount($charge->amount - $charge->amount_refunded, $charge->currency);
    }

    /**
     * A subscription Stripe already knows about (e.g. its webhook has not arrived yet), so a
     * second Checkout would charge twice.
     */
    private function hasStripeSubscription(): bool
    {
        if (! $this->user()->hasStripeId()) {
            return false;
        }

        $subscriptions = $this->user()->stripe()->subscriptions->all(['customer' => $this->user()->stripe_id, 'status' => 'all', 'limit' => 20]);

        foreach ($subscriptions->data as $subscription) {
            if (in_array($subscription->status, ['active', 'trialing', 'past_due', 'incomplete', 'unpaid'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  callable(Subscription): mixed  $change
     */
    private function changeSubscription(callable $change, string $done): bool
    {
        $subscription = $this->subscription;
        abort_unless($subscription instanceof Subscription, 404);

        try {
            $change($subscription);
        } catch (Throwable $exception) {
            Log::warning('Stripe subscription change failed', ['user' => $this->user()->id, 'error' => $exception->getMessage()]);
            $this->dispatch('app-toast', title: __('Payment is not available right now.'), icon: 'error');

            return false;
        }

        unset($this->subscription, $this->nextPayment);
        $this->dispatch('app-toast', title: $done);

        return true;
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $user = auth()->user();
    $plans = resolve(Plans::class);
    $plan = $user->currentPlan();
    $subscription = $this->subscription;
    $grace = $subscription?->onGracePeriod() ?? false;
    $huf = \App\Enums\Currency::HUF;
    $prices = config('budget.prices.plus');
    $date = fn (\Carbon\CarbonInterface $day): string => $day->year.'. '.\App\Support\Dates::short($day);
    $next = $this->nextPayment;
    $status = match (true) {
        $grace => __('Cancelled. Plusz stays until :date.', ['date' => $date($subscription->ends_at)]),
        $subscription !== null && $next !== null => __('Next payment: :date · :amount', ['date' => $date($next['date']), 'amount' => $next['amount']]),
        $subscription !== null => __('Paid with a card.'),
        $plan === Plan::PlusTrial => __('Free until :date, then Alap. Nothing is charged.', ['date' => $date($plans->trialEndsAt($user))]),
        $plan === Plan::Plus => $user->plan_until !== null ? __('Until :date.', ['date' => $date($user->plan_until)]) : __('Set on your account.'),
        default => __('Free. Salary, fixed items and budgets.'),
    };
    $canBuy = $subscription === null && $plan !== Plan::Plus;
    $plusItems = [
        [__('Pockets'), __('reserve, savings, goals')],
        [__('Loans'), __('prepayment and when you will be loan-free')],
        [__('Putting aside'), __('transfers and monthly saving in the plan')],
    ];
    $soon = [__('Couple mode – a shared budget for two'), __('Bank statement import'), __('Analysis: months side by side, trends, spotting subscriptions')];
    $chosenPrice = $prices[$interval === 'month' ? 'month' : 'year'];
    $trialEnd = $plan === Plan::PlusTrial ? $plans->trialEndsAt($user) : null;
@endphp

<x-pages::settings.layout :heading="__('Subscription')">
    <section @class(['rounded-[28px] p-5', 'bg-hero' => $plan->isPlus(), 'bg-surface' => ! $plan->isPlus()]) data-test="plan-current">
        <div @class(['text-[15px]', 'text-hero-sub' => $plan->isPlus(), 'text-muted' => ! $plan->isPlus()])>{{ __('Your plan') }}</div>
        <div @class(['mt-1 text-[34px] font-extrabold tracking-[-0.03em]', 'text-hero-ink' => $plan->isPlus()])>{{ $plan->label() }}</div>
        <p @class(['num mt-1 text-[15px]', 'text-hero-soft' => $plan->isPlus(), 'text-ink-2' => ! $plan->isPlus()]) data-test="plan-status">{{ $status }}</p>
        @if ($grace)
            <x-ui.button size="md" wire:click="resume" class="mt-4 w-full" data-test="resume">{{ __('I’ll stay with Plusz') }}</x-ui.button>
        @endif
    </section>

    @if ($canBuy)
        <div class="mt-3 grid grid-cols-2 gap-2" role="radiogroup" aria-label="{{ __('Billing period') }}" data-test="prices">
            @foreach (['month' => [__('Monthly'), $prices['month'], __('/ month'), null], 'year' => [__('Yearly'), $prices['year'], __('/ year'), __(':amount a month', ['amount' => money((int) round($prices['year'] / 12), $huf)])]] as $key => [$label, $price, $unit, $equivalent])
                <button type="button" role="radio" aria-checked="{{ $interval === $key ? 'true' : 'false' }}" wire:click="$set('interval', '{{ $key }}')"
                        @class(['press rounded-[20px] border-[1.5px] p-4 text-left', 'border-accent bg-picked' => $interval === $key, 'border-surface bg-surface' => $interval !== $key]) data-test="interval-{{ $key }}">
                    <span class="block text-[13px] text-muted">{{ $label }}</span>
                    <span class="num mt-1 block text-xl font-bold">{{ money($price, $huf) }} <span class="text-[13px] font-medium text-muted">{{ $unit }}</span></span>
                    @if ($equivalent)<span class="num mt-0.5 block text-xs text-accent">{{ $equivalent }}</span>@endif
                </button>
            @endforeach
        </div>

        <div class="num mt-3 rounded-[20px] bg-surface px-4 py-3.5 text-[13px] leading-[1.5] text-ink-2" data-test="subscription-summary">
            <div class="text-[15px] font-semibold text-ink">{{ __('MoneySight Plusz · :price :unit', ['price' => money($chosenPrice, $huf), 'unit' => $interval === 'month' ? __('/ month') : __('/ year')]) }}</div>
            <ul class="mt-1.5 grid gap-1">
                <li>{{ __('Renews automatically every :period until you cancel it.', ['period' => $interval === 'month' ? __('month') : __('year')]) }}</li>
                @if ($trialEnd !== null && $trialEnd->greaterThan(now()->addDays(2)))
                    <li>{{ __('Your trial days stay: the first payment is on :date.', ['date' => $date($trialEnd)]) }}</li>
                @endif
                <li>{{ __('Cancel any time here; Plusz stays until the end of the paid time.') }}</li>
                <li>{{ __('Within :days days you can withdraw and get the full amount back.', ['days' => config('budget.withdrawal_days')]) }}</li>
                <li>{{ config('budget.operator.vat_note') }}</li>
            </ul>
            <div class="mt-2"><a href="{{ route('legal', 'aszf') }}" target="_blank" class="text-accent underline underline-offset-2">{{ __('Terms of service') }}</a> · <a href="{{ route('legal', 'adatvedelem') }}" target="_blank" class="text-accent underline underline-offset-2">{{ __('Privacy policy') }}</a></div>
        </div>

        <x-ui.button wire:click="subscribe" wire:loading.attr="disabled" class="mt-3 w-full" data-test="subscribe">{{ __('Subscribe – with an obligation to pay') }}</x-ui.button>
        <p class="mt-2 px-1.5 text-xs text-muted">{{ __('Card payment through Stripe.') }}</p>
    @endif

    <x-ui.section-label class="mt-5" :label="__('What is in Plusz?')" />
    <x-ui.card class="p-5">
        <div class="grid gap-3.5">
            @foreach ($plusItems as [$title, $text])
                <div class="flex items-start gap-3">
                    <x-ui.icon name="check_circle" :size="22" class="mt-px text-accent" />
                    <span><span class="block text-[15px] font-medium">{{ $title }}</span><span class="block text-[13px] text-muted">{{ $text }}</span></span>
                </div>
            @endforeach
        </div>
    </x-ui.card>

    <div class="mt-4 px-1.5" data-test="coming-soon">
        <div class="text-[13px] font-semibold text-muted">{{ __('Coming soon') }}</div>
        <ul class="mt-1.5 grid gap-1 text-[13px] text-muted">
            @foreach ($soon as $item)
                <li class="flex items-start gap-2"><span class="mt-[7px] size-1 shrink-0 rounded-full bg-faint"></span>{{ $item }}</li>
            @endforeach
        </ul>
        <p class="mt-1.5 text-xs text-faint">{{ __('These are plans, not part of the subscription today.') }}</p>
    </div>

    @if ($this->invoices !== [])
        <x-ui.section-label class="mt-5" :label="__('Invoices')" />
        <x-ui.card class="px-5 py-1" data-test="invoices">
            @foreach ($this->invoices as $invoice)
                <a href="{{ $invoice['url'] }}" target="_blank" rel="noopener" @class(['flex min-h-[52px] items-center justify-between gap-3', 'border-t border-line' => ! $loop->first])>
                    <span class="num text-[15px]">{{ $date($invoice['date']) }}</span>
                    <span class="num flex items-center gap-2 text-[15px] font-semibold">
                        @unless ($invoice['paid'])<span class="text-xs font-medium text-warn">{{ __('unpaid') }}</span>@endunless
                        {{ $invoice['amount'] }}
                        <x-ui.icon name="download" :size="18" class="text-faint" />
                    </span>
                </a>
            @endforeach
        </x-ui.card>
    @endif

    @if ($subscription !== null)
        <x-ui.card class="mt-5 px-5 py-1">
            <button type="button" wire:click="billingPortal" class="flex h-[52px] w-full items-center justify-between text-left text-[15px]" data-test="billing-portal">
                {{ __('Card and billing details') }}<x-ui.icon name="open_in_new" :size="18" class="text-faint" />
            </button>
            @if ($this->canWithdraw)
                <button type="button" class="flex min-h-[52px] w-full flex-col justify-center border-t border-line py-2 text-left text-[15px]" data-test="withdraw"
                        x-data x-on:click="if (await window.appConfirm({ title: @js(__('Withdraw from the subscription?')), body: @js(__('Plusz ends now and you get back what you paid. Alap stays, nothing is deleted.')), confirm: @js(__('Withdraw')), danger: true })) $wire.withdraw()">
                    <span>{{ __('Withdrawal') }}</span>
                    <span class="text-xs text-muted">{{ __('until :date, with a full refund', ['date' => $date($this->withdrawalEndsAt($subscription))]) }}</span>
                </button>
            @endif
            @unless ($grace)
                <button type="button" class="flex h-[52px] w-full items-center border-t border-line text-left text-[15px] text-danger" data-test="cancel"
                        x-data x-on:click="if (await window.appConfirm({ title: @js(__('Cancel Plusz?')), body: @js($next ? __('Plusz stays until :date, then Alap comes back. Nothing is deleted.', ['date' => $date($next['date'])]) : __('Plusz stays until the end of the paid time, then Alap comes back. Nothing is deleted.')), confirm: @js(__('Cancel Plusz')), danger: true })) $wire.cancel()">
                    {{ __('Cancel Plusz') }}
                </button>
            @endunless
        </x-ui.card>
    @endif
</x-pages::settings.layout>
