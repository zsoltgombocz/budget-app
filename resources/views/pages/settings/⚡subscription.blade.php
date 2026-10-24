<?php

use App\Enums\Plan;
use App\Models\User;
use App\Services\Plans;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * "Előfizetés": the plan the user has, what is in Plusz, the prices and the one 14-day Plusz offer.
 * Payment is not live yet, so nothing here charges anything; the page says so.
 */
new #[Title('Subscription')] class extends Component {
    /**
     * "Kipróbálom": the 14-day Plusz offer. No card, nothing charged; afterwards Alap comes back.
     */
    public function startOffer(): void
    {
        try {
            $until = resolve(Plans::class)->startOffer($this->user());
        } catch (ValidationException $exception) {
            $this->dispatch('app-toast', title: (string) collect($exception->errors())->flatten()->first(), icon: 'error');

            return;
        }

        $this->dispatch('app-toast', title: __('The Plusz trial has started'), subtitle: __('14 days free · until :date', ['date' => \App\Support\Dates::short($until)]));
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
    $huf = \App\Enums\Currency::HUF;
    $prices = config('budget.prices');
    $date = fn (\Carbon\CarbonInterface $day): string => $day->year.'. '.\App\Support\Dates::short($day);
    $status = match ($plan) {
        Plan::Trial => __('The trial runs until :date. It has Alap.', ['date' => $date($plans->trialEndsAt($user))]),
        Plan::PlusTrial => __('Free until :date. Then Alap comes back; nothing is charged.', ['date' => $date($user->plus_offer_until)]),
        Plan::Plus => $user->plan_until !== null ? __('Until :date.', ['date' => $date($user->plan_until)]) : __('Set on your account, with no end.'),
        Plan::Basic => $user->plan_until !== null ? __('Until :date.', ['date' => $date($user->plan_until)]) : __('Everything for the month.'),
    };
    $soon = [__('Couple mode – a shared budget for two'), __('Bank statement import'), __('Spreading yearly costs, forecasts for several goals'), __('Analysis: months side by side, trends, spotting subscriptions')];
@endphp

<x-pages::settings.layout :heading="__('Subscription')">
    <section @class(['rounded-[28px] p-5', 'bg-hero' => $plan->isPlus(), 'bg-surface' => ! $plan->isPlus()]) data-test="plan-current">
        <div @class(['text-[15px]', 'text-hero-sub' => $plan->isPlus(), 'text-muted' => ! $plan->isPlus()])>{{ __('Your plan') }}</div>
        <div @class(['mt-1 text-[34px] font-extrabold tracking-[-0.03em]', 'text-hero-ink' => $plan->isPlus()])>{{ $plan->label() }}</div>
        <p @class(['mt-1 text-[15px]', 'text-hero-soft' => $plan->isPlus(), 'text-ink-2' => ! $plan->isPlus()])>{{ $status }}</p>
    </section>

    @if ($plans->canStartOffer($user))
        <x-ui.card class="mt-3 p-5" data-test="plus-offer">
            <div class="text-[17px] font-semibold">{{ __('Try Plusz free for 14 days') }}</div>
            <div class="mt-1 text-sm text-ink-2">{{ __('On the 14th day Alap comes back. No card, nothing is charged.') }}</div>
            <x-ui.button size="md" wire:click="startOffer" class="mt-3.5 w-full" data-test="start-offer">{{ __('I’ll try it') }}</x-ui.button>
        </x-ui.card>
    @endif

    <x-ui.section-label class="mt-5" :label="__('What is in Plusz?')" />
    <x-ui.card class="p-5">
        <div class="flex items-start gap-3">
            <x-ui.icon name="check_circle" :size="22" class="mt-px text-accent" />
            <span><span class="block text-[15px] font-medium">{{ __('When will I be loan-free?') }}</span><span class="block text-[13px] text-muted">{{ __('loan planner, with prepayment') }}</span></span>
        </div>
        <div class="mt-4 text-[13px] font-semibold text-muted">{{ __('Coming soon') }}</div>
        <ul class="mt-2 grid gap-1.5 text-sm text-ink-2">
            @foreach ($soon as $item)
                <li class="flex items-start gap-2"><span class="mt-[7px] size-1.5 shrink-0 rounded-full bg-faint"></span>{{ $item }}</li>
            @endforeach
        </ul>
    </x-ui.card>

    <x-ui.section-label class="mt-5" :label="__('Prices')" />
    <x-ui.card class="px-5 py-1" data-test="prices">
        @foreach (['basic' => __('Alap'), 'plus' => __('Plusz')] as $key => $name)
            <div @class(['flex items-baseline justify-between gap-3 py-3.5', 'border-b border-line' => ! $loop->last])>
                <span class="text-[15px] font-medium">{{ $name }}</span>
                <span class="num text-[13px] text-ink-2">{{ __(':month / month or :year / year', ['month' => money($prices[$key]['month'], $huf), 'year' => money($prices[$key]['year'], $huf)]) }}</span>
            </div>
        @endforeach
    </x-ui.card>
    <p class="mt-2 px-1.5 text-xs text-muted">{{ __('Payment starts soon. Until then Alap stays free after the trial too, and nothing is charged.') }}</p>
</x-pages::settings.layout>
