<?php

use App\Enums\PrepayMode;
use App\Models\Loan;
use App\Models\Pocket;
use App\Models\User;
use App\Services\Data\LoanPlan;
use App\Services\LoanPlanner;
use App\Services\PeriodService;
use App\Support\Dates;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Loan')] class extends Component {
    public Loan $loan;

    #[Computed]
    public function fund(): ?Pocket
    {
        return $this->loan->pockets()->whereNotNull('prepay_step')->first();
    }

    /**
     * What the plan puts into the prepayment pocket each month.
     */
    #[Computed]
    public function monthlySaving(): int
    {
        return (int) ($this->fund?->budgetLines()->sum('amount') ?? 0);
    }

    #[Computed]
    public function plusz(): bool
    {
        return $this->user()->hasPlusz();
    }

    /**
     * The loan with the monthly saving, prepaid in the pocket's steps, the way the bank applied the last prepayment.
     */
    #[Computed]
    public function plan(): ?LoanPlan
    {
        $loan = $this->loan;

        if ($loan->thm === null || $loan->remaining_months === null || $loan->principal_balance <= 0) {
            return null;
        }

        return app(LoanPlanner::class)->plan(
            principal: $loan->principal_balance,
            thm: $loan->thm,
            installment: $loan->installment,
            remainingMonths: $loan->remaining_months,
            monthlySaving: $this->monthlySaving,
            step: (int) ($this->fund->prepay_step ?? 500_000 * $this->user()->settings()->currency->minorPerMajor()),
            mode: $loan->prepay_mode,
            firstMonth: app(PeriodService::class)->today($this->user()->settings())->addMonth(),
            alreadySaved: max(0, $this->fund->balance ?? 0),
        );
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $loan = $this->loan;
    $plan = $this->plan;
    $fund = $this->fund;
    $term = $loan->prepay_mode === PrepayMode::ReduceTerm;
    $sooner = $plan?->monthsSooner() ?? 0;
@endphp

<div class="px-4 pb-10">
    <a href="{{ route('pockets', ['ful' => 'hitelek']) }}" wire:navigate class="-ml-1.5 mt-6 inline-flex h-11 items-center gap-1 text-base text-ink-2" data-test="back">
        <x-ui.icon name="chevron_left" :size="22" />{{ __('My money') }}
    </a>
    <h1 class="mt-1 text-[28px] font-bold tracking-[-0.02em]">{{ $loan->name }}</h1>
    <div class="num mt-0.5 text-sm text-muted">{{ __(':owed owed · :installment / month', ['owed' => money_number($loan->principal_balance), 'installment' => money_number($loan->monthlyPayment())]) }}</div>

    <div class="relative">
        <div @class(['pointer-events-none select-none opacity-45 blur-[5px] grayscale' => ! $this->plusz]) @if (! $this->plusz) aria-hidden="true" @endif>
            <section class="mt-[18px] rounded-[28px] bg-hero px-5 pt-[22px] pb-5" data-test="loan-planner">
                @if ($plan === null)
                    <div class="text-[15px] text-hero-sub">{{ __('Loan-free by') }}</div>
                    <p class="mt-2 text-sm text-hero-soft">{{ __('Add the APR and the months left to the loan, and we work out when it ends.') }}</p>
                @elseif ($term)
                    <div class="text-[15px] text-hero-sub">{{ __('Loan-free by') }}</div>
                    <div class="num mt-1 text-5xl leading-[1.05] font-extrabold tracking-[-0.04em] text-hero-ink">{{ Dates::monthYear($plan->endWith) }}</div>
                    <div class="num mt-2 h-5 text-[15px] font-semibold text-accent">
                        @if ($sooner > 0)
                            {{ trans_choice('{1} :count month sooner|[2,*] :count months sooner', $sooner, ['count' => $sooner]) }}
                        @else
                            {{ __('As the bank says') }}
                        @endif
                    </div>
                @else
                    <div class="text-[15px] text-hero-sub">{{ __('Your installment a year from now') }}</div>
                    <div class="num mt-1 flex items-baseline gap-1.5">
                        <span class="text-5xl leading-[1.05] font-extrabold tracking-[-0.04em] text-hero-ink">~{{ money_number($plan->installmentInYear + $loan->insurance) }}</span>
                        <span class="text-lg text-hero-sub">{{ __(':currency/mo', ['currency' => user_currency()->symbol()]) }}</span>
                    </div>
                    <div class="num mt-2 h-5 text-[15px] font-semibold text-accent">
                        @if ($plan->installmentAfterFirst !== null && $plan->prepayments !== [])
                            {{ __('already ~:amount from :date', ['amount' => money($plan->installmentAfterFirst + $loan->insurance), 'date' => Dates::short($plan->prepayments[0])]) }}
                        @else
                            {{ __('As the bank says') }}
                        @endif
                    </div>
                @endif

                @if ($plan !== null && $fund)
                    <div class="mt-[22px] flex items-baseline justify-between border-t border-hero-soft/12 pt-[18px]" data-test="monthly-saving">
                        <span class="text-[15px] text-hero-soft">{{ __('I put aside every month') }}</span>
                        <span class="num text-xl font-bold text-hero-ink">{{ money($this->monthlySaving) }}</span>
                    </div>
                    @if ($plan->savedInterest() > 0)
                        <div class="num mt-2 text-sm text-hero-soft">{{ __('about :amount less interest', ['amount' => money((int) (round($plan->savedInterest() / 10_000) * 10_000))]) }}</div>
                    @endif
                @endif
            </section>
        </div>

        @unless ($this->plusz)
            <div class="absolute inset-x-0 top-10 rounded-[28px] bg-surface-2 px-5 pt-6 pb-5 text-center shadow-[0_16px_40px_rgba(0,0,0,.5)]" data-test="plusz-lock">
                <span class="mx-auto flex size-[52px] items-center justify-center rounded-full bg-warn-tint text-warn"><x-ui.icon name="lock" :size="24" /></span>
                <h2 class="mt-3.5 text-[22px] font-bold tracking-[-0.01em]">{{ __('When will you be loan-free?') }}</h2>
                <p class="mt-2 text-[15px] leading-[1.45] text-ink-2">{{ __('With Plusz we work it out for a monthly saving.') }}</p>
                <x-ui.button :href="route('subscription')" wire:navigate class="mt-[18px] w-full">{{ __('Switch to Plusz') }}</x-ui.button>
            </div>
        @endunless
    </div>

    @if ($fund)
        <div class="mt-3 rounded-[24px] bg-surface px-5 py-[18px]" data-test="next-prepayment">
            <div class="flex items-baseline justify-between">
                <span class="text-base font-semibold">{{ __('Next prepayment') }}</span>
                <span class="num text-[13px] text-muted">{{ __('in steps of :amount', ['amount' => money_number((int) $fund->prepay_step)]) }}</span>
            </div>
            <div class="num mt-3 flex items-baseline gap-1.5">
                <span class="text-[28px] font-bold tracking-[-0.02em]">{{ money_number($fund->balance) }}</span>
                <span class="text-[15px] text-muted">/ {{ money_number((int) $fund->prepay_step) }}</span>
            </div>
            <div class="mt-3 h-2.5 overflow-hidden rounded-[5px] bg-track"><div class="tile-grow h-full rounded-[5px] bg-accent" style="width: {{ min(100, round(max(0, $fund->balance) / max(1, (int) $fund->prepay_step) * 100)) }}%"></div></div>
            <x-ui.button variant="secondary" :href="route('pockets', ['elotorlesztes' => $loan->id])" wire:navigate class="mt-3.5 w-full !h-12" data-test="record-prepayment">{{ __('I prepaid') }}</x-ui.button>
        </div>
    @else
        <div class="mt-3 rounded-[24px] bg-surface px-5 py-[18px]" data-test="no-prepay-pocket">
            <div class="text-base font-semibold">{{ __('Saving up to prepay?') }}</div>
            <p class="mt-1 text-sm text-muted">{{ __('Add a pocket for it and we show when you will be loan-free.') }}</p>
            <x-ui.button :href="route('pockets', ['uj-persely' => $loan->id])" wire:navigate icon="add" class="mt-3.5 w-full !h-12" data-test="add-prepay-pocket">{{ __('Prepayment pocket') }}</x-ui.button>
            <x-ui.button variant="secondary" :href="route('pockets', ['elotorlesztes' => $loan->id])" wire:navigate class="mt-2 w-full !h-12" data-test="record-prepayment">{{ __('I prepaid') }}</x-ui.button>
        </div>
    @endif

    <div class="num mt-3 rounded-[24px] bg-surface px-5 py-1">
        <div class="flex h-[52px] items-center justify-between text-[15px]"><span class="text-ink-2">{{ __('APR') }}</span><span>{{ $loan->thm !== null ? number_format($loan->thm, 1, ',', ' ').'%' : '–' }}</span></div>
        <div class="flex h-[52px] items-center justify-between border-t border-line text-[15px]"><span class="text-ink-2">{{ __('Months left') }}</span><span>{{ $loan->remaining_months ?? '–' }}</span></div>
        <div class="flex h-[52px] items-center justify-between border-t border-line text-[15px]"><span class="text-ink-2">{{ __('Last prepayment') }}</span><span>{{ $term ? __('term got shorter') : __('installment got lower') }}</span></div>
        <a href="{{ route('pockets', ['hitel' => $loan->id]) }}" wire:navigate class="flex h-[52px] items-center justify-between border-t border-line text-[15px] text-accent" data-test="edit-loan">{{ __('Edit loan') }}<x-ui.icon name="chevron_right" :size="20" /></a>
    </div>
</div>
