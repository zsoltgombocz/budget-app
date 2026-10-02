<?php

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
        $this->user()->transactions()->whereKey($transactionId)->whereRelation('period', 'status', 'open')->first()?->delete();
        $this->refresh();

        $this->dispatch('budget-updated');
        $this->dispatch('app-toast', title: __('Entry removed.'), icon: 'delete');
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
        unset($this->period, $this->days, $this->overview, $this->neighbours);
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
                $fixed = $this->overview->fixedItems;
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
            <h1 class="truncate text-[30px] font-semibold tracking-[-0.03em]">{{ Dates::monthName($period->starts_on) }}</h1>
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
            <x-ui.icon-button icon="settings" :href="route('settings')" wire:navigate :label="__('Settings')" data-test="open-settings" />
        </div>
    </div>

    <x-ui.card class="mx-4 mb-1 mt-[18px] rounded-[22px] px-[18px] py-4">
        <div class="num flex items-baseline justify-between text-[13px] text-muted">
            <span>{{ __('Variable spending so far') }}</span>
            <span>{{ __('budget :amount', ['amount' => money($forecast->variablePlanned)]) }}</span>
        </div>
        <x-ui.amount :value="$forecast->variableSpent" size="lg" class="mt-1 [&>span:first-child]:text-[26px]" />
        <x-ui.bar :value="$forecast->variablePlanned > 0 ? $forecast->variableSpent / $forecast->variablePlanned : 0" :tone="$forecast->variableSpent > $forecast->variablePlanned ? 'danger' : ($forecast->variablePlanned > 0 && $forecast->variableSpent / $forecast->variablePlanned >= 0.8 ? 'warn' : 'accent')" class="mt-2.5" />

        @if ($period->isOpen())
            <a href="{{ route('close', $period) }}" wire:navigate class="mt-3.5 flex h-12 items-center justify-center gap-2 rounded-2xl bg-surface-2 text-[15px] font-semibold" data-test="start-close">
                <x-ui.icon name="task_alt" :size="20" class="text-accent" />{{ __('Close the month') }}
            </a>
        @elseif ($closeRecord)
            <a href="{{ route('close', $period) }}" wire:navigate class="mt-3.5 grid grid-cols-3 gap-2 rounded-2xl bg-surface-2 px-3 py-2.5 text-xs" data-test="close-summary">
                <span><span class="block text-muted">{{ __('Leftover') }}</span><span class="num block text-sm font-semibold">{{ money($closeRecord->leftover) }}</span></span>
                <span><span class="block text-muted">{{ __('To the reserve') }}</span><span class="num block text-sm">{{ money($closeRecord->to_reserve) }}</span></span>
                <span><span class="block text-muted">{{ __('Rest') }}</span><span class="num block text-sm">{{ money($closeRecord->to_invest) }}</span></span>
            </a>
        @endif
    </x-ui.card>

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
                        <button type="button" x-on:click="selected = @js(['id' => $row['id'], 'title' => $row['title'], 'note' => $row['note'], 'amount' => money($row['amount'])])"
                                @class(['grid w-full grid-cols-[36px_minmax(0,1fr)_auto] items-center gap-3 py-[11px] text-left', 'border-b border-line' => $border]) data-test="transaction">
                            <x-ui.icon-tile :icon="$row['icon']" :size="36" />
                            <span class="min-w-0"><span class="block truncate text-[15px]">{{ $row['title'] }}</span>@if ($row['pocket'])<span class="mt-0.5 block truncate text-xs text-accent">{{ __('paid from :pocket', ['pocket' => $row['pocket']]) }}</span>@elseif ($row['note'])<span class="mt-0.5 block truncate text-xs text-muted">{{ $row['note'] }}</span>@endif</span>
                            <span class="num text-[15px] font-medium">{{ money_number($row['amount']) }}</span>
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
                            <span class="num text-[15px] font-medium text-accent">+{{ money_number($row['amount']) }}</span>
                        </div>
                    @else
                        <div @class(['grid grid-cols-[36px_minmax(0,1fr)_auto] items-center gap-3 py-[11px]', 'border-b border-line' => $border])>
                            <x-ui.icon-tile icon="autorenew" tone="muted" :size="36" />
                            <span><span class="block text-[15px]">{{ __('Fixed items') }}</span><span class="mt-0.5 block text-xs text-muted">{{ trans_choice('{1} :count item automatically|[2,*] :count items automatically', $row['count'], ['count' => $row['count']]) }}</span></span>
                            <span class="num text-[15px] font-medium">{{ money_number($row['amount']) }}</span>
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
    <div x-show="selected" x-cloak class="fixed inset-0 z-50" role="dialog" aria-modal="true">
        <div x-show="selected" x-transition.opacity class="absolute inset-0 bg-black/55" x-on:click="selected = null"></div>
        <div class="absolute inset-x-0 bottom-0 mx-auto max-w-lg rounded-t-[30px] bg-surface px-4 pb-[calc(1.5rem+env(safe-area-inset-bottom))] pt-2" x-show="selected" x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0" x-transition:leave="transition duration-200 ease-in" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full">
            <div class="mx-auto h-[5px] w-9 rounded-full bg-ink/18"></div>
            <div class="py-5 text-center">
                <div class="text-sm text-muted" x-text="selected?.title"></div>
                <div class="num mt-1 text-[40px] font-semibold tracking-[-0.03em]" x-text="selected?.amount"></div>
                <div class="mt-1 text-sm text-muted" x-show="selected?.note" x-text="selected?.note"></div>
            </div>
            @if ($period->isOpen())
                <x-ui.button variant="danger" icon="delete" class="w-full" x-on:click="$wire.delete(selected.id); selected = null" data-test="delete-transaction">{{ __('Delete entry') }}</x-ui.button>
            @endif
            <x-ui.button variant="ghost" class="mt-1 w-full" x-on:click="selected = null">{{ __('Close') }}</x-ui.button>
        </div>
    </div>
</div>
