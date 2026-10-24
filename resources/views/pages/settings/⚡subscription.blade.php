<?php

use App\Enums\Plan;
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
            $checkout = $this->user()->newSubscription(Plans::SUBSCRIPTION, $price)->checkout([
                'success_url' => route('subscription', ['fizetes' => 'ok']),
                'cancel_url' => route('subscription'),
                'locale' => 'hu',
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
        $this->changeSubscription(fn (Subscription $subscription) => $subscription->cancel(), __('Plusz cancelled'));
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
     * @param  callable(Subscription): mixed  $change
     */
    private function changeSubscription(callable $change, string $done): void
    {
        $subscription = $this->subscription;
        abort_unless($subscription instanceof Subscription, 404);

        try {
            $change($subscription);
        } catch (Throwable $exception) {
            Log::warning('Stripe subscription change failed', ['user' => $this->user()->id, 'error' => $exception->getMessage()]);
            $this->dispatch('app-toast', title: __('Payment is not available right now.'), icon: 'error');

            return;
        }

        unset($this->subscription, $this->nextPayment);
        $this->dispatch('app-toast', title: $done);
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
        <div class="mt-3 grid grid-cols-2 gap-2" role="radiogroup" data-test="prices">
            @foreach (['month' => [__('Monthly'), $prices['month'], null], 'year' => [__('Yearly'), $prices['year'], '−'.(int) round(100 - $prices['year'] / ($prices['month'] * 12) * 100).'%']] as $key => [$label, $price, $badge])
                <button type="button" role="radio" aria-checked="{{ $interval === $key ? 'true' : 'false' }}" wire:click="$set('interval', '{{ $key }}')"
                        @class(['press rounded-[20px] border-[1.5px] p-4 text-left', 'border-accent bg-picked' => $interval === $key, 'border-surface bg-surface' => $interval !== $key]) data-test="interval-{{ $key }}">
                    <span class="flex items-center justify-between text-[13px] text-muted">{{ $label }}@if ($badge)<span class="rounded-full bg-accent/16 px-2 py-0.5 text-xs font-semibold text-accent">{{ $badge }}</span>@endif</span>
                    <span class="num mt-1 block text-xl font-bold">{{ money($price, $huf) }}</span>
                </button>
            @endforeach
        </div>
        <x-ui.button wire:click="subscribe" wire:loading.attr="disabled" class="mt-3 w-full" data-test="subscribe">{{ __('Switch to Plusz') }}</x-ui.button>
        <p class="mt-2 px-1.5 text-xs text-muted">{{ __('Card payment through Stripe. You can cancel any time; Plusz stays until the end of the paid time.') }}</p>
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
        <div class="mt-4 text-[13px] font-semibold text-muted">{{ __('Coming soon') }}</div>
        <ul class="mt-2 grid gap-1.5 text-sm text-ink-2">
            @foreach ($soon as $item)
                <li class="flex items-start gap-2"><span class="mt-[7px] size-1.5 shrink-0 rounded-full bg-faint"></span>{{ $item }}</li>
            @endforeach
        </ul>
    </x-ui.card>

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
            @unless ($grace)
                <button type="button" class="flex h-[52px] w-full items-center border-t border-line text-left text-[15px] text-danger" data-test="cancel"
                        x-data x-on:click="if (await window.appConfirm({ title: @js(__('Cancel Plusz?')), body: @js($next ? __('Plusz stays until :date, then Alap comes back. Nothing is deleted.', ['date' => $date($next['date'])]) : __('Plusz stays until the end of the paid time, then Alap comes back. Nothing is deleted.')), confirm: @js(__('Cancel Plusz')), danger: true })) $wire.cancel()">
                    {{ __('Cancel Plusz') }}
                </button>
            @endunless
        </x-ui.card>
    @endif
</x-pages::settings.layout>
