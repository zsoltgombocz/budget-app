<?php

use App\Actions\Budget\SkipLine;
use App\Enums\LineType;
use App\Models\Category;
use App\Models\Period;
use App\Models\User;
use App\Services\OverviewService;
use App\Services\PeriodService;
use App\Support\Dates;
use App\Support\Icons;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Month')] class extends Component {
    #[Url(as: 'periodus')]
    public ?int $periodId = null;

    #[Url(as: 'kategoria')]
    public ?int $categoryId = null;

    public function mount(): void
    {
        if ($this->periodId === null || ! $this->user()->periods()->whereKey($this->periodId)->exists()) {
            $this->periodId = app(PeriodService::class)->current($this->user())->id;
        }
    }

    public function delete(int $transactionId): void
    {
        $transaction = $this->user()->transactions()->whereKey($transactionId)->whereRelation('period', 'status', 'open')->first();

        if ($transaction === null) {
            return;
        }

        // A spending paid from a pocket moved pocket money too: that one has no undo here.
        $restore = $transaction->pocket_id === null ? $transaction->only(['period_id', 'category_id', 'amount', 'note']) + ['occurred_on' => $transaction->occurred_on->toDateString()] : null;
        $transaction->delete();
        $this->refresh();

        $this->dispatch('budget-updated');
        $this->dispatch('app-toast', title: __('Entry removed.'), icon: 'delete', undo: $restore !== null ? 'undo-delete-transaction' : null, params: ['entry' => $restore]);
    }

    /**
     * Put back a deleted spending, only into one of the user's open periods.
     *
     * @param  array<string, mixed>  $entry
     */
    #[On('undo-delete-transaction')]
    public function restore(array $entry): void
    {
        $user = $this->user();
        $period = $user->periods()->whereKey((int) ($entry['period_id'] ?? 0))->where('status', 'open')->first();
        $category = $user->categories()->withTrashed()->find((int) ($entry['category_id'] ?? 0));
        $amount = (int) ($entry['amount'] ?? 0);

        if ($period === null || $category === null || $amount <= 0) {
            return;
        }

        $user->transactions()->create([
            'period_id' => $period->id,
            'category_id' => $category->id,
            'amount' => $amount,
            'occurred_on' => $period->contains(CarbonImmutable::parse((string) ($entry['occurred_on'] ?? ''))) ? $entry['occurred_on'] : $period->starts_on->toDateString(),
            'note' => is_string($entry['note'] ?? null) ? $entry['note'] : null,
            'source' => \App\Enums\TransactionSource::Manual,
        ]);
        $this->refresh();

        $this->dispatch('budget-updated');
    }

    /**
     * Take a skipped fixed item back into this month.
     */
    public function unskip(int $budgetLineId): void
    {
        app(SkipLine::class)->handle($this->user(), $this->period, $budgetLineId, skip: false);
        $this->refresh();

        $this->dispatch('budget-updated');
    }

    public function showPeriod(int $periodId): void
    {
        if ($this->user()->periods()->whereKey($periodId)->exists()) {
            $this->periodId = $periodId;
            $this->refresh();
        }
    }

    #[On('budget-updated')]
    public function refresh(): void
    {
        unset($this->period, $this->days, $this->overview, $this->neighbours, $this->noSpendDays);
    }

    #[Computed]
    public function period(): Period
    {
        return $this->user()->periods()->findOrFail($this->periodId);
    }

    #[Computed]
    public function overview(): \App\Services\Data\Overview
    {
        $today = app(PeriodService::class)->today($this->user()->settings());

        return app(OverviewService::class)->forPeriod($this->user(), $this->period, $today);
    }

    /**
     * @return array{previous: int|null, next: int|null}
     */
    #[Computed]
    public function neighbours(): array
    {
        $periods = $this->user()->periods();

        return [
            'previous' => (clone $periods)->whereDate('starts_on', '<', $this->period->starts_on->toDateString())->orderByDesc('starts_on')->value('id'),
            'next' => (clone $periods)->whereDate('starts_on', '>', $this->period->starts_on->toDateString())->orderBy('starts_on')->value('id'),
        ];
    }

    /**
     * Rows grouped by day, newest first: spending, "didn't spend" marks and the payday.
     *
     * @return list<array{date: CarbonImmutable, total: int, payday: bool, rows: list<array<string, mixed>>}>
     */
    #[Computed]
    public function days(): array
    {
        $period = $this->period;
        $today = app(PeriodService::class)->today($this->user()->settings());
        $days = [];

        $transactions = $period->transactions()->with(['category', 'pocket'])
            ->when($this->categoryId, fn ($query, int $categoryId) => $query->where('category_id', $categoryId))
            ->orderByDesc('occurred_on')->orderByDesc('id')->get();

        foreach ($transactions as $transaction) {
            $key = $transaction->occurred_on->toDateString();
            $days[$key] ??= ['date' => $transaction->occurred_on, 'total' => 0, 'payday' => false, 'rows' => []];
            if ($transaction->pocket_id === null) {
                $days[$key]['total'] += $transaction->amount;
            }
            $days[$key]['rows'][] = [
                'kind' => 'spending',
                'id' => $transaction->id,
                'icon' => Icons::forCategory($transaction->category?->icon),
                'title' => $transaction->category?->name ?? '',
                'note' => $transaction->note,
                'amount' => $transaction->amount,
                'pocket' => $transaction->pocket?->name,
                'category_id' => $transaction->category_id,
                'date' => $transaction->occurred_on->toDateString(),
            ];
        }

        if ($this->categoryId === null) {
            $marks = $this->user()->dayMarks()
                ->whereDate('date', '>=', $period->starts_on->toDateString())
                ->whereDate('date', '<=', $period->ends_on->toDateString())
                ->get();

            foreach ($marks as $mark) {
                $key = $mark->date->toDateString();
                $days[$key] ??= ['date' => $mark->date, 'total' => 0, 'payday' => false, 'rows' => []];
                $days[$key]['rows'][] = ['kind' => 'no-spend', 'markedAt' => $mark->created_at?->setTimezone($this->user()->settings()->timezone)->format('H:i')];
            }

            if (! $period->starts_on->greaterThan($today)) {
                $key = $period->starts_on->toDateString();
                $fixed = array_values(array_filter($this->overview->fixedItems, fn ($item) => ! $item->skipped));
                $days[$key] ??= ['date' => $period->starts_on, 'total' => 0, 'payday' => true, 'rows' => []];
                $days[$key]['payday'] = true;
                $days[$key]['rows'][] = ['kind' => 'income', 'amount' => $period->income()];
                if ($fixed !== []) {
                    $days[$key]['rows'][] = ['kind' => 'fixed', 'count' => count($fixed), 'amount' => array_sum(array_map(fn ($item) => $item->line->planned(), $fixed))];
                }
            }
        }

        krsort($days);

        return array_values($days);
    }

    /**
     * The days of the period marked "Ma nem költöttem", as 0-based day indexes.
     *
     * @return list<int>
     */
    #[Computed]
    public function noSpendDays(): array
    {
        $period = $this->period;

        return $this->user()->dayMarks()
            ->whereDate('date', '>=', $period->starts_on->toDateString())->whereDate('date', '<=', $period->ends_on->toDateString())
            ->get()->map(fn (\App\Models\DayMark $mark): int => (int) $period->starts_on->diffInDays($mark->date))->values()->all();
    }

    #[Computed]
    public function category(): ?Category
    {
        return $this->categoryId === null ? null : $this->user()->categories()->withTrashed()->find($this->categoryId);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $period = $this->period;
    $overview = $this->overview;
    $forecast = $overview->forecast;
    $today = $overview->today;
    $closeRecord = $period->isOpen() ? null : $period->close()->first();
    $hasSpending = collect($this->days)->contains(fn ($day) => collect($day['rows'])->contains('kind', 'spending'));
@endphp

<div x-data="{ selected: null }">
    <div class="flex items-end justify-between gap-2 px-6 pt-3.5">
        <div class="min-w-0">
            <h1 class="truncate text-[30px] font-semibold tracking-[-0.03em]">{{ Dates::monthName($period->nameDate()) }}</h1>
            <div class="num mt-0.5 text-[13px] text-muted">
                {{ Dates::range($period->starts_on, $period->ends_on) }} ·
                @if (! $period->isOpen())
                    {{ __('closed') }}
                @elseif ($period->contains($today))
                    {{ trans_choice('{1} :count day left|[2,*] :count days left', $forecast->remainingDays, ['count' => $forecast->remainingDays]) }}
                @else
                    {{ __('open') }}
                @endif
            </div>
        </div>
        <div class="flex gap-2">
            <x-ui.icon-button icon="chevron_left" :label="__('Previous period')" wire:click="showPeriod({{ $this->neighbours['previous'] ?? 0 }})" :disabled="$this->neighbours['previous'] === null" class="disabled:opacity-30" data-test="previous-period" />
            <x-ui.icon-button icon="chevron_right" :label="__('Next period')" wire:click="showPeriod({{ $this->neighbours['next'] ?? 0 }})" :disabled="$this->neighbours['next'] === null" class="disabled:opacity-30" />
        </div>
    </div>

    @if ($period->isOpen())
        @php
            $left = $forecast->expectedLeftover;
            $negative = $left < 0;
            $segments = array_filter([
                ['value' => $forecast->committed, 'class' => 'bg-hero-mid'],
                ['value' => $forecast->variableExpected, 'class' => 'bg-hero-bar'],
                ['value' => max(0, $left), 'class' => 'bg-accent'],
            ], fn ($segment) => $segment['value'] > 0);
        @endphp
        <section @class(['mx-4 mt-5 rounded-[28px] px-5 pt-[22px] pb-5', 'bg-hero' => ! $negative, 'bg-danger-tint' => $negative]) data-test="month-end">
            <div @class(['text-[15px]', 'text-hero-sub' => ! $negative, 'text-danger-soft' => $negative])>{{ __('Left at month end') }}</div>
            <div class="num mt-1 flex items-baseline gap-1.5">
                <span @class(['text-5xl leading-none font-extrabold tracking-[-0.04em]', 'text-hero-ink' => ! $negative, 'text-danger' => $negative])>{{ money_number($left) }}</span>
                <span @class(['text-xl', 'text-hero-sub' => ! $negative, 'text-danger-soft' => $negative])>{{ user_currency()->symbol() }}</span>
            </div>

            @if ($segments !== [])
                <div class="mt-5 flex h-4 gap-[3px] overflow-hidden rounded-lg" role="img" data-test="month-split"
                     aria-label="{{ __('Income split: fixed :fixed, budgets :budgets, left :left', ['fixed' => money($forecast->committed), 'budgets' => money($forecast->variableExpected), 'left' => money($left)]) }}">
                    @foreach ($segments as $segment)
                        <span class="tile-grow {{ $segment['class'] }} rounded-[3px]" style="flex: {{ $segment['value'] }}"></span>
                    @endforeach
                </div>
            @endif

            <div class="num mt-3.5 text-[15px]">
                @php $topUps = app(PeriodService::class)->topUps($period); @endphp
                <div class="flex h-[34px] items-center justify-between"><span class="text-hero-soft">{{ __('Income') }}</span><span class="font-semibold text-hero-ink">{{ money_number($forecast->income - $topUps) }}</span></div>
                @if ($topUps > 0)
                    <div class="flex h-[34px] items-center justify-between"><span class="text-hero-soft">{{ __('From pockets to the budget') }}</span><span class="text-hero-ink">+{{ money_number($topUps) }}</span></div>
                @endif
                <div class="flex h-[34px] items-center justify-between"><span class="flex items-center gap-2 text-hero-soft"><span class="size-2.5 rounded-[3px] bg-hero-mid"></span>{{ __('Fixed items') }}</span><span class="text-hero-ink">−{{ money_number($forecast->committed) }}</span></div>
                <div class="flex h-[34px] items-center justify-between"><span class="flex items-center gap-2 text-hero-soft"><span class="size-2.5 rounded-[3px] bg-hero-bar"></span>{{ __('Budgets, expected') }}</span><span class="text-hero-ink">−{{ money_number($forecast->variableExpected) }}</span></div>
                <div class="flex h-[34px] items-center justify-between"><span class="flex items-center gap-2 text-hero-soft"><span class="size-2.5 rounded-[3px] bg-accent"></span>{{ __('Left') }}</span><span @class(['font-bold', 'text-accent' => ! $negative, 'text-danger' => $negative])>{{ money_number($left) }}</span></div>
            </div>

            @php $closer = app(\App\Services\PeriodCloser::class); @endphp
            @if ($closer->canClose(auth()->user(), $period))
                <a href="{{ route('close', $period) }}" wire:navigate class="press mt-3 flex h-12 items-center justify-center rounded-2xl bg-hero-2 text-[15px] font-semibold text-hero-ink" data-test="start-close">{{ __('Close the month') }}</a>
            @else
                <div class="num mt-3 flex h-12 items-center justify-center gap-2 rounded-2xl border border-hero-2 text-sm text-hero-sub" data-test="close-later">
                    <x-ui.icon name="event_available" :size="18" />{{ __('Closing from :date', ['date' => Dates::short($closer->closableFrom($period))]) }}
                </div>
            @endif
        </section>

        @if ($overview->skipped() !== [])
            <div class="mx-4 mt-3 rounded-[24px] bg-surface px-4 py-1.5" data-test="skipped-items">
                <div class="flex h-11 items-center text-base font-semibold">{{ __('Skipped this month') }}</div>
                @foreach ($overview->skipped() as $item)
                    <div class="flex min-h-[56px] items-center gap-3 border-t border-line" wire:key="skipped-{{ $item->line->lineId }}">
                        <span class="min-w-0 flex-1 truncate text-base text-ink-2">{{ $item->line->categoryName }}</span>
                        <span class="num text-[15px] text-muted line-through">{{ money_number($item->line->regular()) }}</span>
                        <button type="button" wire:click="unskip({{ (int) $item->line->lineId }})" class="press h-9 shrink-0 rounded-full bg-surface-3 px-3.5 text-sm font-semibold" data-test="unskip">{{ __('Undo') }}</button>
                    </div>
                @endforeach
            </div>
        @endif
    @elseif ($closeRecord)
        <section class="mx-4 mt-5 rounded-[28px] bg-hero px-5 pt-[22px] pb-5" data-test="close-summary">
            <div class="text-[15px] text-hero-sub">{{ __('Was left') }}</div>
            <div class="num mt-1 flex items-baseline gap-1.5">
                <span class="text-5xl leading-none font-extrabold tracking-[-0.04em] text-hero-ink">{{ money_number($closeRecord->leftover) }}</span>
                <span class="text-xl text-hero-sub">{{ user_currency()->symbol() }}</span>
            </div>
            <div class="num mt-3.5 text-[15px]">
                <div class="flex h-[34px] items-center justify-between"><span class="text-hero-soft">{{ __('To the reserve') }}</span><span class="text-hero-ink">{{ money_number($closeRecord->to_reserve) }}</span></div>
                <div class="flex h-[34px] items-center justify-between"><span class="text-hero-soft">{{ __('Rest') }}</span><span class="text-hero-ink">{{ money_number($closeRecord->to_invest) }}</span></div>
            </div>
            <livewire:reopen-closing :period-id="$period->id" :key="'reopen-'.$period->id" />
        </section>

        @php
            $budgetRows = array_values(array_filter($closeRecord->breakdown['categories'] ?? [], fn ($row) => is_array($row) && ($row['type'] ?? null) === 'variable' && (($row['planned'] ?? 0) > 0 || ($row['actual'] ?? 0) > 0)));
            usort($budgetRows, fn ($a, $b) => ($b['diff'] ?? 0) <=> ($a['diff'] ?? 0));
        @endphp
        @if ($budgetRows !== [])
            <div class="mx-4 mt-3 rounded-[24px] bg-surface px-4 py-1.5" data-test="plan-vs-actual">
                <div class="flex h-11 items-center justify-between">
                    <span class="text-base font-semibold">{{ __('Budgets') }}</span>
                    <span class="text-[13px] text-muted">{{ __('spent / budget') }}</span>
                </div>
                @foreach ($budgetRows as $row)
                    @php $over = ($row['diff'] ?? 0) > 0; @endphp
                    <div class="num flex min-h-[48px] items-center justify-between gap-3 border-t border-line text-[15px]">
                        <span class="min-w-0 flex-1 truncate text-ink-2">{{ $row['name'] ?? '' }}</span>
                        <span @class(['font-semibold', 'text-danger' => $over])>{{ money_number((int) ($row['actual'] ?? 0)) }}</span>
                        <span class="w-[92px] text-right text-muted">/ {{ money_number((int) ($row['planned'] ?? 0)) }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    @endif

    @php
        $chart = app(\App\Services\DailyChart::class);
        $noSpendDays = $this->noSpendDays;
        $bars = $chart->bars($overview->dailySpent, $noSpendDays, $period->totalDays(), $period->contains($today));
    @endphp
    @if ($overview->dailySpent !== [])
        <div class="mx-4 mt-3 rounded-[24px] bg-surface px-4 pt-4 pb-3.5" data-test="daily-chart">
            <div class="flex items-baseline justify-between">
                <h2 class="text-base font-semibold">{{ __('Daily spending') }}</h2>
                <span class="num text-[13px] text-muted">{{ __('average :amount / day', ['amount' => money_number($chart->average($overview->dailySpent))]) }}</span>
            </div>
            <svg viewBox="0 0 {{ $chart::WIDTH }} {{ $chart::HEIGHT }}" class="mt-3.5 block h-auto w-full" aria-hidden="true">
                @foreach ($bars as $bar)
                    @if ($bar->kind === 'no_spend')
                        <circle cx="{{ $bar->x + 3 }}" cy="{{ $chart::HEIGHT - 3 }}" r="3" fill="var(--app-accent)" />
                    @elseif ($bar->kind === 'spent' || $bar->kind === 'today')
                        <rect x="{{ $bar->x }}" y="{{ $bar->y }}" width="6" height="{{ $bar->height }}" rx="2" fill="{{ $bar->kind === 'today' ? 'var(--app-accent)' : 'var(--app-ink-2)' }}" class="tile-rise" />
                    @else
                        <rect x="{{ $bar->x }}" y="{{ $chart::HEIGHT - 2 }}" width="6" height="2" rx="1" fill="var(--app-surface-3)" />
                    @endif
                @endforeach
            </svg>
            <div class="mt-2 flex justify-between text-xs text-muted">
                <span>{{ Dates::short($period->starts_on) }}</span>
                @if ($noSpendDays !== [])
                    <span class="flex items-center gap-1.5"><span class="size-1.5 rounded-full bg-accent"></span>{{ trans_choice('{1} :count no-spend day|[2,*] :count no-spend days', count($noSpendDays), ['count' => count($noSpendDays)]) }}</span>
                @endif
                <span>{{ Dates::short($period->ends_on) }}</span>
            </div>
        </div>
    @endif

    @if ($this->category)
        <div class="mx-4 mt-3 flex">
            <button type="button" wire:click="$set('categoryId', null)" class="flex h-9 items-center gap-1.5 rounded-xl bg-accent/14 px-3 text-[13px] font-medium text-accent">
                {{ $this->category->name }} <x-ui.icon name="close" :size="16" />
            </button>
        </div>
    @endif

    @foreach ($this->days as $day)
        <div class="px-4" wire:key="day-{{ $day['date']->toDateString() }}">
            <div class="num flex justify-between px-1.5 pb-2 pt-[18px] text-[13px]">
                <span class="font-semibold text-ink-2">{{ Dates::day($day['date'], $today) }}@if ($day['payday']) · {{ __('Payday') }}@endif</span>
                @if ($day['total'] > 0 || ! $day['payday'])<span class="text-muted">{{ money($day['total']) }}</span>@endif
            </div>
            <div class="rounded-[20px] bg-surface px-4">
                @foreach ($day['rows'] as $row)
                    @php $border = ! $loop->last; @endphp
                    @if ($row['kind'] === 'spending')
                        <button type="button" x-on:click="selected = @js(['id' => $row['id'], 'title' => $row['title'], 'note' => $row['note'], 'amount' => money($row['amount']), 'edit' => $row['pocket'] === null ? ['id' => $row['id'], 'categoryId' => $row['category_id'], 'amount' => intdiv($row['amount'], user_currency()->minorPerMajor()), 'date' => $row['date'], 'note' => $row['note']] : null])"
                                @class(['grid w-full grid-cols-[36px_minmax(0,1fr)_auto] items-center gap-3 py-[11px] text-left', 'border-b border-line' => $border]) data-test="transaction">
                            <x-ui.icon-tile :icon="$row['icon']" :size="36" />
                            <span class="min-w-0"><span class="block truncate text-[15px]">{{ $row['title'] }}</span>@if ($row['pocket'])<span class="mt-0.5 block truncate text-xs text-accent">{{ __('paid from :pocket', ['pocket' => $row['pocket']]) }}</span>@elseif ($row['note'])<span class="mt-0.5 block truncate text-xs text-muted">{{ $row['note'] }}</span>@endif</span>
                            <x-ui.amount size="sm" :value="$row['amount']" class="text-[15px] font-medium" />
                        </button>
                    @elseif ($row['kind'] === 'no-spend')
                        <div @class(['grid grid-cols-[36px_minmax(0,1fr)_auto] items-center gap-3 py-[11px]', 'border-b border-line' => $border])>
                            <x-ui.icon-tile icon="do_not_disturb_on" tone="accent" :size="36" />
                            <span><span class="block text-[15px] text-ink-2">{{ __("Didn't spend") }}</span>@if ($row['markedAt'])<span class="mt-0.5 block text-xs text-muted">{{ __('marked at :time', ['time' => $row['markedAt']]) }}</span>@endif</span>
                            <span></span>
                        </div>
                    @elseif ($row['kind'] === 'income')
                        <div @class(['grid grid-cols-[36px_minmax(0,1fr)_auto] items-center gap-3 py-[11px]', 'border-b border-line' => $border])>
                            <x-ui.icon-tile icon="payments" tone="accent" :size="36" />
                            <span class="text-[15px]">{{ __('Salary') }}</span>
                            <x-ui.amount size="sm" :value="$row['amount']" signed class="text-[15px] font-medium text-accent" />
                        </div>
                    @else
                        <div @class(['grid grid-cols-[36px_minmax(0,1fr)_auto] items-center gap-3 py-[11px]', 'border-b border-line' => $border])>
                            <x-ui.icon-tile icon="autorenew" tone="muted" :size="36" />
                            <span><span class="block text-[15px]">{{ __('Fixed items') }}</span><span class="mt-0.5 block text-xs text-muted">{{ trans_choice('{1} :count item automatically|[2,*] :count items automatically', $row['count'], ['count' => $row['count']]) }}</span></span>
                            <x-ui.amount size="sm" :value="$row['amount']" class="text-[15px] font-medium" />
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endforeach

    @if (! $hasSpending)
        <div class="flex flex-col items-center gap-2.5 px-10 pb-6 pt-12 text-center" data-test="empty-state">
            <x-ui.icon-tile icon="event_note" tone="muted" :size="56" class="!bg-surface" />
            <div class="mt-1 text-lg font-semibold">{{ $this->category ? __('No spending in this category') : __('Clean slate') }}</div>
            <div class="text-sm leading-normal text-pretty text-muted">{{ __('Your daily spending and the “didn’t spend” days land here. Record the first one with the + button.') }}</div>
        </div>
    @endif

    {{-- Transaction actions --}}
    <div x-show="selected" x-cloak x-trap.inert="selected" class="fixed inset-0 z-50" role="dialog" aria-modal="true">
        <div x-show="selected" x-transition.opacity class="absolute inset-0 bg-black/55" x-on:click="selected = null"></div>
        <div class="absolute inset-x-0 bottom-0 mx-auto max-w-lg rounded-t-[30px] bg-surface px-4 pb-[calc(1.5rem+env(safe-area-inset-bottom))] pt-2" x-show="selected" x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0" x-transition:leave="transition duration-200 ease-in" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full">
            <div class="mx-auto h-[5px] w-9 rounded-full bg-ink/18"></div>
            <div class="py-5 text-center">
                <div class="text-sm text-muted" x-text="selected?.title"></div>
                <div class="num mt-1 text-[40px] font-semibold tracking-[-0.03em]" x-text="selected?.amount"></div>
                <div class="mt-1 text-sm text-muted" x-show="selected?.note" x-text="selected?.note"></div>
            </div>
            @if ($period->isOpen())
                <x-ui.button icon="edit" class="w-full" x-show="selected?.edit" x-on:click="$dispatch('edit-entry', selected.edit); selected = null" data-test="edit-transaction">{{ __('Edit') }}</x-ui.button>
                <x-ui.button variant="danger" icon="delete" class="mt-2 w-full" x-on:click="$wire.delete(selected.id); selected = null" data-test="delete-transaction">{{ __('Delete entry') }}</x-ui.button>
            @endif
            <x-ui.button variant="ghost" class="mt-1 w-full" x-on:click="selected = null">{{ __('Close') }}</x-ui.button>
        </div>
    </div>
</div>
