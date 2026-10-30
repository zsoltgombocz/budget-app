<?php

use App\Actions\Budget\MarkNoSpendDay;
use App\Actions\Budget\SkipLine;
use App\Actions\Budget\ToggleLinePaid;
use App\Models\Category;
use App\Models\User;
use App\Services\Data\Overview;
use App\Services\Data\Curve;
use App\Services\Data\Milestone;
use App\Services\Data\StreakState;
use App\Services\Data\WeeklyChallenge;
use App\Services\MonthCurve;
use App\Services\OverviewService;
use App\Services\Rewards;
use App\Services\Streak;
use Carbon\CarbonImmutable;
use App\Support\Icons;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Today')] class extends Component {
    /** @var list<string> milestones reached since the last visit, celebrated one after the other */
    public array $celebrations = [];

    public function mount(): void
    {
        $rewards = app(Rewards::class);
        $rewards->sync($this->user(), $this->today());
        $this->celebrations = $rewards->takeCelebrations($this->user());
    }

    public function nextCelebration(): void
    {
        array_shift($this->celebrations);
    }

    public function togglePaid(int $budgetLineId): void
    {
        $item = collect($this->overview->fixedItems)->first(fn ($item): bool => $item->line->lineId === $budgetLineId);
        $paid = app(ToggleLinePaid::class)->handle($this->user(), $this->overview->period, $budgetLineId);
        unset($this->overview);

        if ($paid) {
            $this->dispatch('app-toast', title: __('Done').' · '.($item->line->categoryName ?? ''), icon: 'check', undo: 'undo-paid', params: ['budgetLineId' => $budgetLineId]);
        }
    }

    #[On('undo-paid')]
    public function undoPaid(int $budgetLineId): void
    {
        $status = $this->overview->period->lineStatuses()->where('budget_line_id', $budgetLineId)->whereNotNull('paid_on')->first();

        if ($status !== null) {
            app(ToggleLinePaid::class)->handle($this->user(), $this->overview->period, $budgetLineId);
            unset($this->overview);
        }
    }

    public function togglePaidFromSheet(int $budgetLineId): void
    {
        $this->togglePaid($budgetLineId);
        $this->dispatch('close-sheet', name: 'due-'.$budgetLineId);
    }

    /**
     * Leave a fixed item out of this month: its amount stays in the month-end leftover.
     */
    public function skipDue(int $budgetLineId): void
    {
        $item = collect($this->overview->fixedItems)->first(fn ($item): bool => $item->line->lineId === $budgetLineId);
        app(SkipLine::class)->handle($this->user(), $this->overview->period, $budgetLineId);
        unset($this->overview);

        $this->dispatch('close-sheet', name: 'due-'.$budgetLineId);
        $this->dispatch('budget-updated');
        $this->dispatch('app-toast', title: __('Skipped this month'), subtitle: $item ? __('+:amount left at month end', ['amount' => money($item->line->regular())]) : null, icon: 'event_busy', undo: 'undo-skip-due', params: ['budgetLineId' => $budgetLineId]);
    }

    #[On('undo-skip-due')]
    public function unskipDue(int $budgetLineId): void
    {
        app(SkipLine::class)->handle($this->user(), $this->overview->period, $budgetLineId, skip: false);
        unset($this->overview);

        $this->dispatch('budget-updated');
    }

    public function markNoSpend(): void
    {
        app(MarkNoSpendDay::class)->handle($this->user());
        unset($this->overview);

        $this->dispatch('budget-updated');
        $this->dispatch('app-toast', title: __("I didn't spend today"), subtitle: __('Noted, no reminder today.'), icon: 'do_not_disturb_on', undo: 'undo-no-spend');
    }

    public function unmarkNoSpend(): void
    {
        app(MarkNoSpendDay::class)->unmark($this->user());
        unset($this->overview);

        $this->dispatch('budget-updated');
    }

    public function markTransferred(int $periodCloseId): void
    {
        $this->user()->periodCloses()->whereKey($periodCloseId)->update(['surplus_transferred_at' => now()]);
        unset($this->pendingTransfers);

        $this->dispatch('app-toast', title: __('Marked as transferred.'));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\PeriodClose>
     */
    #[Computed]
    public function pendingTransfers(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->user()->periodCloses()->with('surplusAccount')
            ->whereNull('surplus_transferred_at')->whereNotNull('surplus_account_id')->where('to_invest', '>', 0)
            ->latest('id')->get();
    }

    #[On('budget-updated')]
    public function refresh(): void
    {
        $rewards = app(Rewards::class);
        $rewards->sync($this->user(), $this->today());
        $this->celebrations = [...$this->celebrations, ...$rewards->takeCelebrations($this->user())];

        unset($this->overview, $this->icons, $this->streak, $this->challenge, $this->milestones, $this->activeDates);
    }

    #[Computed]
    public function curve(): Curve
    {
        $overview = $this->overview;
        $forecast = $overview->forecast;

        return app(MonthCurve::class)->build(
            start: $forecast->income - $forecast->committed,
            dailySpent: $overview->dailySpent,
            totalDays: $overview->period->totalDays(),
            expectedEnd: $forecast->expectedLeftover,
            plannedEnd: $forecast->plannedLeftover,
        );
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function activeDates(): array
    {
        return app(Streak::class)->activeDates($this->user(), $this->today());
    }

    #[Computed]
    public function streak(): StreakState
    {
        return app(Streak::class)->state($this->activeDates, $this->today());
    }

    #[Computed]
    public function challenge(): WeeklyChallenge
    {
        return app(Rewards::class)->weeklyChallenge($this->user(), $this->today());
    }

    /**
     * @return list<Milestone>
     */
    #[Computed]
    public function milestones(): array
    {
        return app(Rewards::class)->overview($this->user(), $this->today());
    }

    private function today(): CarbonImmutable
    {
        return app(\App\Services\PeriodService::class)->today($this->user()->settings());
    }

    #[Computed]
    public function overview(): Overview
    {
        return app(OverviewService::class)->forUser($this->user());
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function icons(): array
    {
        return $this->user()->categories()->withTrashed()->get(['id', 'icon'])
            ->mapWithKeys(fn (Category $category): array => [$category->id => Icons::forCategory($category->icon)])
            ->all();
    }

    #[Computed]
    public function hasSpending(): bool
    {
        return $this->overview->period->transactions()->exists();
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $overview = $this->overview;
    $forecast = $overview->forecast;
    $period = $overview->period;
    $negative = $forecast->expectedLeftover < 0;
    $categories = $forecast->categories;
    $over = array_values(array_filter($categories, fn ($c) => $c->isOver()));
    $due = $period->isOpen() ? $overview->dueSoon() : [];
    $curve = $this->curve;
    [$todayX, $todayY] = $curve->today();
    $streak = $this->streak;
    $challenge = $this->challenge;
    $user = auth()->user();
    // On Alap the savings lines stay in the plan but do not count: say so, with their total.
    $lockedSavings = $user->hasPlusz() ? 0 : array_sum(array_map(fn ($line) => $line->planned(), array_filter(app(\App\Services\PlanService::class)->liveLines($user), fn ($line) => in_array($line->type, [\App\Enums\LineType::Transfer, \App\Enums\LineType::Sinking], true))));
    $trialDaysLeft = $user->currentPlan() === \App\Enums\Plan::PlusTrial ? (int) ceil(now()->diffInDays(app(\App\Services\Plans::class)->trialEndsAt($user))) : null;
@endphp

<div class="min-h-dvh px-4 pb-6">
    <h1 class="sr-only">{{ __('Today') }}</h1>
    <div class="flex items-center justify-between pt-6">
        <span class="num text-sm text-muted" data-test="period-chip">{{ \App\Support\Dates::range($period->starts_on, $period->ends_on) }} · {{ __(':day. day', ['day' => max(1, $period->elapsedDays($overview->today))]) }}</span>
        <button type="button" x-data x-on:click="$dispatch('open-sheet', 'streak')"
                class="press flex h-9 items-center gap-1.5 rounded-full bg-warn-tint px-3 text-[15px] font-bold text-warn"
                aria-label="{{ trans_choice('{0} No streak yet|{1} :count-day streak|[2,*] :count-day streak', $streak->current, ['count' => $streak->current]) }}" data-test="streak-chip">
            <x-ui.icon name="local_fire_department" :size="18" :fill="true" />
            <span class="num">{{ $streak->current }}</span>
        </button>
    </div>

    <section @class(['relative mt-3.5 overflow-hidden rounded-[28px] pt-[22px]', 'bg-hero' => ! $negative, 'bg-danger-tint' => $negative]) data-test="expected-leftover">
        <div class="px-5">
            <div class="flex items-center justify-between">
                <span @class(['text-[15px]', 'text-hero-sub' => ! $negative, 'text-danger-soft' => $negative])>{{ __('Left at month end') }}</span>
                <button type="button" x-data x-on:click="$dispatch('open-sheet', 'curve-info')"
                        @class(['press hit -my-1.5 -mr-2 flex size-8 items-center justify-center rounded-full', 'bg-hero-2 text-hero-sub' => ! $negative, 'bg-danger/16 text-danger-soft' => $negative])
                        aria-label="{{ __('What does the curve show?') }}" data-test="curve-info">
                    <x-ui.icon name="info" :size="18" />
                </button>
            </div>
            <div class="num mt-1 flex items-baseline gap-2">
                <x-ui.count-up :value="$forecast->expectedLeftover" @class(['text-[64px] leading-none font-extrabold tracking-[-0.045em]', 'text-hero-ink' => ! $negative, 'text-danger' => $negative]) />
                <span @class(['text-2xl font-medium', 'text-hero-sub' => ! $negative, 'text-danger-soft' => $negative])>{{ user_currency()->symbol() }}</span>
            </div>
        </div>

        <svg viewBox="0 0 {{ $curve::WIDTH }} {{ $curve::HEIGHT }}" role="img" @class(['mt-3.5 block h-auto w-full', 'text-accent' => ! $negative, 'text-danger' => $negative])
             aria-label="{{ __('Left for the month: :now today, :end at month end', ['now' => money($overview->availableNow()), 'end' => money($forecast->expectedLeftover)]) }}" data-test="month-curve">
            <path d="{{ $curve->area() }}" fill="currentColor" fill-opacity=".14" class="late-in" />
            <line x1="0" y1="{{ $curve->planY }}" x2="{{ $curve::WIDTH }}" y2="{{ $curve->planY }}" stroke="var(--app-hero-sub)" stroke-opacity=".35" stroke-dasharray="2 4" />
            <text x="18" y="{{ $curve->planY - \App\Services\MonthCurve::TOP < 18 ? $curve->planY + 14 : $curve->planY - 6 }}" fill="var(--app-hero-sub)" font-size="10.5" class="num late-in">{{ __('plan :amount', ['amount' => money_number($forecast->plannedLeftover)]) }}</text>
            <text x="18" y="114" fill="var(--app-hero-sub)" fill-opacity=".7" font-size="10">{{ \App\Support\Dates::short($period->starts_on) }}</text>
            <text x="{{ $curve::WIDTH - 18 }}" y="114" text-anchor="end" fill="var(--app-hero-sub)" fill-opacity=".7" font-size="10">{{ \App\Support\Dates::short($period->ends_on) }}</text>
            <path d="{{ $curve->line() }}" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" class="curve-draw" />
            <path d="M{{ $todayX }},{{ $todayY }} L{{ $curve->endX }},{{ $curve->endY }}" fill="none" stroke="currentColor" stroke-width="2" stroke-dasharray="3 5" stroke-linecap="round" class="late-in" />
            <circle cx="{{ $todayX }}" cy="{{ $todayY }}" r="5" fill="currentColor" class="dot-pulse" />
            <circle cx="{{ $todayX }}" cy="{{ $todayY }}" r="5" fill="currentColor" stroke="{{ $negative ? 'var(--app-danger-tint)' : 'var(--app-hero)' }}" stroke-width="2.5" class="late-in" />
            <circle cx="{{ $curve->endX }}" cy="{{ $curve->endY }}" r="4" fill="{{ $negative ? 'var(--app-danger-tint)' : 'var(--app-hero)' }}" stroke="currentColor" stroke-width="2" class="late-in" />
            <text x="{{ min(max($todayX, 52), $curve::WIDTH - 52) }}" y="{{ $todayY - 14 }}" text-anchor="middle" fill="var(--app-hero-ink)" font-size="11" font-weight="600" class="num late-in">{{ __('today · :amount', ['amount' => money_number($overview->availableNow())]) }}</text>
        </svg>
    </section>

    @if ($lockedSavings > 0)
        <a href="{{ route('subscription') }}" wire:navigate class="press mt-3 flex items-center gap-3 rounded-[24px] bg-surface px-4 py-3.5" data-test="locked-savings">
            <x-ui.icon name="lock" :size="20" class="text-warn" />
            <span class="num min-w-0 flex-1 text-sm text-ink-2">{{ __('On Alap your savings (:amount) do not count in the month.', ['amount' => money($lockedSavings)]) }}</span>
            <x-ui.icon name="chevron_right" :size="20" class="text-faint" />
        </a>
    @endif

    @if ($trialDaysLeft !== null && $trialDaysLeft <= 3)
        <a href="{{ route('subscription') }}" wire:navigate class="press mt-3 flex items-center gap-3 rounded-[24px] bg-surface px-4 py-3.5" data-test="trial-ending">
            <x-ui.icon name="workspace_premium" :size="22" class="text-accent" />
            <span class="min-w-0 flex-1 text-sm text-ink-2">{{ trans_choice('{0} The Plusz trial ends today|{1} The Plusz trial ends tomorrow|[2,*] :count days left of the Plusz trial', $trialDaysLeft, ['count' => $trialDaysLeft]) }}</span>
            <x-ui.icon name="chevron_right" :size="20" class="text-faint" />
        </a>
    @endif

    {{-- Only what the number above does not say: which budgets ran over, or that the plan itself is short. --}}
    @if ($negative && $over !== [])
        <div class="mt-3 flex items-center gap-3 rounded-[24px] bg-surface px-4 py-3.5" data-test="negative-alert">
            <div class="min-w-0 flex-1 text-sm leading-[1.4] text-ink-2">
                {{ trans_choice('{1} Over budget: :names|[2,*] Over budget: :names', count($over), ['names' => implode(', ', array_map(fn ($c) => $c->categoryName, $over))]) }}
            </div>
        </div>
    @elseif ($negative && $forecast->plannedLeftover < 0)
        <a href="{{ route('plan') }}" wire:navigate class="press mt-3 flex items-center gap-3 rounded-[24px] bg-surface px-4 py-3.5" data-test="negative-alert">
            <span class="min-w-0 flex-1 text-sm leading-[1.4] text-ink-2">{{ __('The plan itself spends more than comes in. Check it in the Plan.') }}</span>
            <x-ui.icon name="chevron_right" :size="20" class="text-faint" />
        </a>
    @endif

    @foreach ($this->pendingTransfers as $close)
        <div class="mt-3 flex items-center gap-3 rounded-[24px] bg-surface px-4 py-3.5" wire:key="transfer-{{ $close->id }}" data-test="pending-transfer">
            <x-ui.icon-tile icon="show_chart" tone="accent" />
            <div class="min-w-0 flex-1">
                <div class="num text-[15px] font-semibold">{{ __('Transfer :amount', ['amount' => money($close->to_invest)]) }}</div>
                <div class="truncate text-xs text-muted">{{ __('to :account, from the month-end leftover', ['account' => $close->surplusAccount->name ?? '']) }}</div>
            </div>
            <x-ui.button size="sm" variant="light" wire:click="markTransferred({{ $close->id }})" data-test="mark-transferred">{{ __('Transferred') }}</x-ui.button>
        </div>
    @endforeach

    @if ($due !== [])
        <div class="mt-3 rounded-[24px] bg-surface px-4 py-1.5" data-test="due-items">
            <div class="flex h-11 items-center justify-between">
                <span class="text-base font-semibold">{{ __('Due') }}</span>
                <span class="num text-sm text-muted">{{ money(array_sum(array_map(fn ($item) => $item->line->planned(), $due))) }}</span>
            </div>
            @foreach ($due as $item)
                @php
                    $isTransfer = in_array($item->line->type, [\App\Enums\LineType::Transfer, \App\Enums\LineType::Sinking], true);
                    $late = $item->dueOn->lessThan($overview->today);
                    $isToday = $item->dueOn->isSameDay($overview->today);
                @endphp
                <div class="flex min-h-[60px] items-center gap-3 border-t border-line" wire:key="due-{{ $item->line->lineId }}" data-test="due-item">
                    <button type="button" x-data x-on:click="$dispatch('open-sheet', 'due-{{ $item->line->lineId }}')" class="press flex min-w-0 flex-1 items-center gap-3 text-left" data-test="open-due">
                    <span @class(['flex size-9 shrink-0 items-center justify-center rounded-xl', 'bg-warn-tint text-warn' => $late || $isToday, 'bg-surface-2 text-ink-2' => ! $late && ! $isToday])>
                        <x-ui.icon :name="$this->icons[$item->line->categoryId] ?? 'autorenew'" :size="20" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-base">{{ $item->line->categoryName }}</div>
                        <div @class(['text-[13px]', 'text-warn' => $late || $isToday, 'text-muted' => ! $late && ! $isToday])>
                            {{ $late ? __('since :date', ['date' => \App\Support\Dates::short($item->dueOn)]) : ($isToday ? ($isTransfer ? __('Transfer due today') : __('Due today')) : __('due :date', ['date' => \App\Support\Dates::short($item->dueOn)])) }}
                        </div>
                    </div>
                    <x-ui.amount size="sm" :value="$item->line->planned()" class="text-base font-semibold" />
                    </button>
                    <button type="button" wire:click="togglePaid({{ (int) $item->line->lineId }})" class="press hit h-9 shrink-0 rounded-full bg-surface-3 px-3.5 text-sm font-semibold" aria-label="{{ ($isTransfer ? __('Transferred') : __('Done')).': '.$item->line->categoryName }}" data-test="mark-due-done">{{ $isTransfer ? __('Transferred') : __('Done') }}</button>
                </div>
            @endforeach
        </div>

        @foreach ($due as $item)
            @php $isTransfer = in_array($item->line->type, [\App\Enums\LineType::Transfer, \App\Enums\LineType::Sinking], true); @endphp
            <div wire:key="due-sheet-{{ $item->line->lineId }}">
            <x-ui.sheet :name="'due-'.$item->line->lineId">
                <div class="mt-4 flex items-center gap-3.5">
                    <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-surface-2 text-ink-2"><x-ui.icon :name="$this->icons[$item->line->categoryId] ?? 'autorenew'" :size="24" /></span>
                    <div class="min-w-0">
                        <h2 class="truncate text-xl font-bold tracking-[-0.01em]">{{ $item->line->categoryName }}</h2>
                        <div class="num text-[15px] text-muted">{{ money($item->line->planned()) }}</div>
                    </div>
                </div>
                <x-ui.button class="mt-5 w-full" icon="check" wire:click="togglePaidFromSheet({{ (int) $item->line->lineId }})">{{ $isTransfer ? __('Transferred') : __('Done') }}</x-ui.button>
                <button type="button" wire:click="skipDue({{ (int) $item->line->lineId }})" class="press mt-2.5 flex w-full items-center gap-3 rounded-btn bg-surface-2 px-4 py-3.5 text-left" data-test="skip-due">
                    <x-ui.icon name="event_busy" :size="22" class="text-ink-2" />
                    <span class="min-w-0 flex-1">
                        <span class="block text-base font-semibold">{{ $isTransfer ? __('Not transferring this month') : __('Skip this month') }}</span>
                        <span class="num block text-[13px] text-accent">{{ __('+:amount left at month end', ['amount' => money($item->line->regular())]) }}</span>
                    </span>
                </button>
            </x-ui.sheet>
            </div>
        @endforeach
    @endif

    <button type="button" x-data x-on:click="$dispatch('open-sheet', 'streak')" class="press mt-3 flex w-full items-center gap-3.5 rounded-[24px] bg-surface p-4 text-left" data-test="weekly-challenge">
        <span class="min-w-0 flex-1">
            <span class="block text-[13px] text-muted">{{ $challenge->completed ? __('Weekly challenge done') : __('Weekly challenge') }}</span>
            <span class="mt-0.5 block text-base font-semibold">{{ $challenge->kind === 'no_spend' ? trans_choice('{1} :count no-spend day|[2,*] :count no-spend days', $challenge->target, ['count' => $challenge->target]) : trans_choice('{1} Write in on :count day|[2,*] Write in on :count days', $challenge->target, ['count' => $challenge->target]) }}</span>
        </span>
        <span class="flex gap-1.5" aria-label="{{ __(':done of :target done', ['done' => $challenge->done, 'target' => $challenge->target]) }}">
            @for ($i = 0; $i < $challenge->target; $i++)
                @if ($i < $challenge->done)
                    <span class="flex size-7 items-center justify-center rounded-full bg-accent text-accent-ink"><x-ui.icon name="check" :size="16" :weight="600" /></span>
                @else
                    <span class="size-7 rounded-full bg-surface-2"></span>
                @endif
            @endfor
        </span>
    </button>

    @if (! $this->hasSpending)
        <x-ui.empty-state icon="receipt_long" :title="__('No spending recorded yet')" class="mt-3" data-test="empty-state">
            @if ($forecast->income > 0)
                {{ __('Your income and fixed items are already in. You only need to write down the variable spending – or tap once if you did not spend today.') }}
            @else
                {{ __('Add your salary and fixed costs in the Plan, then write down what you spend.') }}
            @endif
            <x-slot name="actions">
                <x-no-spend-button :marked="$overview->noSpendMarked" />
            </x-slot>
        </x-ui.empty-state>
    @elseif ($overview->spentToday === 0)
        <div class="mt-3 flex justify-center">
            <x-no-spend-button :marked="$overview->noSpendMarked" />
        </div>
    @endif

    @if ($categories !== [])
        <div class="mx-1 mt-6 mb-2.5 flex items-baseline justify-between">
            <h2 class="text-[17px] font-semibold">{{ __('Budgets') }}</h2>
            <span @class(['num text-sm', 'text-danger' => $forecast->variableSpent > $forecast->variablePlanned, 'text-muted' => $forecast->variableSpent <= $forecast->variablePlanned])>{{ money_number($forecast->variableSpent) }} / {{ money_number($forecast->variablePlanned) }}</span>
        </div>
        <div class="grid grid-cols-2 gap-2.5" data-test="categories">
            @foreach ($categories as $category)
                @php
                    $isOver = $category->spent > $category->planned;
                    $left = $category->planned > 0 ? max(0.0, min(1.0, ($category->planned - $category->spent) / $category->planned)) : 0.0;
                @endphp
                <a href="{{ route('month', ['kategoria' => $category->categoryId]) }}" wire:navigate wire:key="category-{{ $category->categoryId }}"
                   class="press relative block h-32 overflow-hidden rounded-[22px] bg-surface" data-test="category-tile">
                    @if ($isOver)
                        <span class="tile-rise absolute inset-0 bg-danger-tint" style="animation-delay: {{ $loop->index * 60 }}ms"></span>
                    @elseif ($left > 0)
                        <span class="tile-rise absolute inset-x-0 bottom-0 bg-fill" style="height: {{ round($left * 100) }}%; animation-delay: {{ $loop->index * 60 }}ms"></span>
                    @endif
                    <span class="relative flex h-full flex-col p-3.5">
                        <span class="flex items-center justify-between gap-2">
                            <span class="truncate text-sm text-ink-2">{{ $category->categoryName }}</span>
                            <x-ui.icon :name="$this->icons[$category->categoryId] ?? 'more_horiz'" :size="18" @class(['text-danger-soft' => $isOver, 'text-hero-sub' => ! $isOver]) />
                        </span>
                        <span class="flex-1"></span>
                        @if ($isOver)
                            <span class="num text-2xl font-bold tracking-[-0.02em] text-danger">−{{ money_number($category->spent - $category->planned) }}</span>
                            <span class="num text-[13px] text-danger-soft">{{ __('over · budget :amount', ['amount' => money_number($category->planned)]) }}</span>
                        @else
                            <span class="num text-2xl font-bold tracking-[-0.02em]">{{ money_number($category->planned - $category->spent) }}</span>
                            <span class="num text-[13px] text-hero-sub">{{ __('left of :amount', ['amount' => money_number($category->planned), 'suffix' => \App\Support\Hungarian::suffix((int) round($category->planned / user_currency()->minorPerMajor()), 'ból', 'ből')]) }}</span>
                        @endif
                    </span>
                </a>
            @endforeach
        </div>
    @endif

    <x-install-card class="mt-3" />

    {{-- Only on devices without an active push subscription, unless blocked or dismissed. --}}
    <a href="{{ route('notifications.onboarding') }}" wire:navigate
       x-data="{
            show: false,
            async init() {
                let dismissed = false
                try { dismissed = localStorage.getItem('push-prompt-dismissed') === '1' } catch (e) {}
                const push = window.budgetPush
                if (dismissed || ! push.supported() || push.permission() === 'denied') return
                this.show = ! (await push.current())
            },
       }"
       x-show="show" x-cloak
       class="mt-3 flex items-center gap-3 rounded-card bg-surface px-[18px] py-4" data-test="push-prompt">
        <x-ui.icon-tile icon="notifications" tone="accent" :fill="true" />
        <span class="min-w-0 flex-1">
            <span class="block text-[15px] font-medium">{{ __('Daily reminder') }}</span>
            <span class="block text-xs text-muted">{{ __('Notifications are off on this device. Turn them on so we can remind you in the evening.') }}</span>
        </span>
        <x-ui.icon name="chevron_right" :size="22" class="text-faint" />
    </a>

    <x-ui.sheet name="curve-info">
        <h2 class="mt-3 text-xl font-bold tracking-[-0.01em]">{{ __('What does the curve show?') }}</h2>
        <div class="num mt-3.5">
            <div class="flex h-[52px] items-center gap-3.5">
                <svg viewBox="0 0 28 12" aria-hidden="true" class="h-3 w-7 shrink-0 text-accent"><path d="M2 6 L26 6" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                <span class="flex-1 text-base">{{ __('Left for the month now') }}</span>
                <span class="text-base font-semibold">{{ money_number($overview->availableNow()) }}</span>
            </div>
            <div class="flex h-[52px] items-center gap-3.5 border-t border-line">
                <svg viewBox="0 0 28 12" aria-hidden="true" class="h-3 w-7 shrink-0 text-accent"><path d="M2 6 L26 6" stroke="currentColor" stroke-width="2.5" stroke-dasharray="4 5" stroke-linecap="round" /></svg>
                <span class="flex-1 text-base">{{ __('Left at month end') }}</span>
                <span @class(['text-base font-bold', 'text-accent' => ! $negative, 'text-danger' => $negative])>{{ money_number($forecast->expectedLeftover) }}</span>
            </div>
            <div class="flex h-[52px] items-center gap-3.5 border-t border-line">
                <svg viewBox="0 0 28 12" aria-hidden="true" class="h-3 w-7 shrink-0 text-hero-sub"><path d="M2 6 L26 6" stroke="currentColor" stroke-opacity=".6" stroke-width="2" stroke-dasharray="2 4" stroke-linecap="round" /></svg>
                <span class="flex-1 text-base">{{ __('Plan') }}</span>
                <span class="text-base font-semibold text-ink-2">{{ money_number($forecast->plannedLeftover) }}</span>
            </div>
        </div>
        <p class="mt-3 text-sm leading-[1.45] text-muted">{{ __('The dashed part assumes you spend what is still in the budgets.') }}</p>
        <x-ui.button variant="secondary" class="mt-5 w-full" x-on:click="$dispatch('close-sheet', 'curve-info')">{{ __('Got it') }}</x-ui.button>
    </x-ui.sheet>

    <x-streak-sheet :streak="$streak" :active-dates="$this->activeDates" :today="$overview->today" :challenge="$challenge" :milestones="$this->milestones" />

    @if ($celebrations !== [])
        <x-milestone-celebration :key="$celebrations[0]" />
    @endif
</div>
