<?php

use App\Enums\Plan;
use App\Models\User;
use App\Services\Plans;
use App\Services\Watching;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * "Előfizetés" (S27): the plan the user has, what is in Plusz, the prices, the one 14-day Plusz
 * offer, and which two watched categories stay visible when Plusz ends. Payment is not live yet,
 * so nothing here charges anything; the page says so.
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

    /**
     * Which watched categories stay visible on Alap (at most two).
     */
    public function keep(int $watchId): void
    {
        $user = $this->user();
        $watch = $user->watchedCategories()->findOrFail($watchId);

        if (! $watch->kept_on_basic && $user->watchedCategories()->where('kept_on_basic', true)->count() >= Watching::BASE_LIMIT) {
            $this->dispatch('app-toast', title: __('Two can stay visible. Untick one first.'), icon: 'error');

            return;
        }

        $watch->update(['kept_on_basic' => ! $watch->kept_on_basic]);
        unset($this->watched);
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\WatchedCategory>
     */
    #[Computed]
    public function watched(): \Illuminate\Support\Collection
    {
        return $this->user()->watchedCategories()->with('category')->orderBy('id')->get()->toBase();
    }

    private function user(): User
    {
        $user = Auth::user();
        assert($user instanceof User);

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
        Plan::Basic => $user->plan_until !== null ? __('Until :date.', ['date' => $date($user->plan_until)]) : __('Everything for the month, two watched categories.'),
    };
    $keptCount = $this->watched->where('kept_on_basic', true)->count();
@endphp

<x-pages::settings.layout :heading="__('Subscription')">
    <x-ui.card class="p-[18px]" data-test="plan-current">
        <div class="flex items-center justify-between gap-3">
            <div class="font-display text-[22px] font-bold">{{ $plan->label() }}</div>
            @if ($plan->isPlus())
                <span class="rounded-full bg-plum-tint px-2.5 py-1 text-xs font-semibold text-plum">{{ __('Active') }}</span>
            @endif
        </div>
        <p class="mt-1 text-[15px] text-ink-2">{{ $status }}</p>
    </x-ui.card>

    @if ($plans->canStartOffer($user))
        <x-ui.card class="mt-3 p-[18px]" data-test="plus-offer">
            <div class="flex items-center gap-3">
                <x-morzsa mood="vidam" :size="56" />
                <div class="min-w-0">
                    <div class="text-[17px] font-semibold">{{ __('Try Plusz free for 14 days') }}</div>
                    <div class="text-[13px] text-ink-2">{{ __('On the 14th day Alap comes back. No card, nothing is charged.') }}</div>
                </div>
            </div>
            <x-ui.button variant="tonal" size="md" wire:click="startOffer" class="mt-3 w-full" data-test="start-offer">{{ __('I’ll try it') }}</x-ui.button>
        </x-ui.card>
    @endif

    <x-ui.section-label class="mt-5" :label="__('What is in Plusz?')" />
    <x-ui.card class="p-[18px]">
        <x-plusz-info />
    </x-ui.card>

    <x-ui.section-label class="mt-5" :label="__('Prices')" />
    <x-ui.card class="px-[18px] py-1" data-test="prices">
        @foreach (['basic' => __('Alap'), 'plus' => __('Plusz')] as $key => $name)
            <div @class(['flex items-baseline justify-between gap-3 py-3', 'border-b border-line' => ! $loop->last])>
                <span class="text-[15px] font-medium">{{ $name }}</span>
                <span class="num text-[13px] text-ink-2">{{ __(':month / month or :year / year', ['month' => money($prices[$key]['month'], $huf), 'year' => money($prices[$key]['year'], $huf)]) }}</span>
            </div>
        @endforeach
    </x-ui.card>
    <p class="mt-2 px-1.5 text-xs text-muted">{{ __('Payment starts soon. Until then Alap stays free after the trial too, and nothing is charged.') }}</p>

    @if ($plan->isPlus() && $this->watched->count() > Watching::BASE_LIMIT)
        <x-ui.section-label class="mt-5" :label="__('When Plusz ends')" />
        <x-ui.card class="px-[18px] py-1" data-test="keep-watched">
            <p class="pt-3 text-[13px] text-ink-2">{{ __('Which 2 watched categories should stay visible?') }}</p>
            @foreach ($this->watched as $watch)
                <button type="button" wire:click="keep({{ $watch->id }})" wire:key="keep-{{ $watch->id }}" role="checkbox" aria-checked="{{ $watch->kept_on_basic ? 'true' : 'false' }}"
                        @class(['focus-ring flex min-h-[52px] w-full items-center justify-between gap-3 text-left text-[15px]', 'border-b border-line' => ! $loop->last]) data-test="keep-{{ $watch->id }}">
                    <span>{{ $watch->category->name ?? '' }}</span>
                    <x-ui.icon :name="$watch->kept_on_basic ? 'check_circle' : 'remove'" :size="22" @class(['text-accent' => $watch->kept_on_basic, 'text-faint' => ! $watch->kept_on_basic]) />
                </button>
            @endforeach
        </x-ui.card>
        <p class="mt-2 px-1.5 text-xs text-muted">
            {{ $keptCount === 0 ? __('If you do not pick, the 2 oldest stay.') : '' }}
            {{ __('The others stay hidden and come back with Plusz. A prepayment saving stays as a fixed line; you can change it any time.') }}
        </p>
    @endif
</x-pages::settings.layout>
