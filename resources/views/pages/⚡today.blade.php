<?php

use App\Actions\Budget\MarkNoSpendDay;
use App\Actions\Budget\ToggleLinePaid;
use App\Models\Category;
use App\Models\User;
use App\Services\Data\Overview;
use App\Services\OverviewService;
use App\Support\Icons;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Today')] class extends Component {
    public const int FIXED_PREVIEW = 4;

    public bool $showAllFixed = false;

    public function togglePaid(int $budgetLineId): void
    {
        app(ToggleLinePaid::class)->handle($this->user(), $this->overview->period, $budgetLineId);
        unset($this->overview);
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
        unset($this->overview, $this->icons);
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
    $fixed = $overview->fixedItems;
    $visibleFixed = $this->showAllFixed ? $fixed : array_slice($fixed, 0, $this::FIXED_PREVIEW);
    $hiddenFixed = array_slice($fixed, $this::FIXED_PREVIEW);
@endphp

<div @class(['min-h-dvh', 'bg-[radial-gradient(120%_40%_at_30%_0%,rgba(242,85,90,0.12),rgba(242,85,90,0)_70%)]' => $negative])>
    <div class="flex items-center justify-between px-5 pt-6">
        <div class="flex h-[34px] items-center gap-2 rounded-full bg-surface px-3.5 text-[13px] font-medium text-ink-2" data-test="period-chip">
            <span @class(['size-1.5 rounded-full', 'bg-danger' => $negative, 'bg-accent' => ! $negative])></span>
            <span class="num">{{ \App\Support\Dates::range($period->starts_on, $period->ends_on) }} · {{ __(':day. day', ['day' => max(1, $period->elapsedDays($overview->today))]) }}</span>
        </div>
    </div>

    <section class="px-6 pb-[26px] pt-[30px]" data-test="expected-leftover">
        <div class="flex items-center gap-2 text-sm text-muted">
            {{ __('Expected leftover at period end') }}
            @if ($negative)
                <span class="inline-flex h-[22px] items-center gap-1 rounded-full bg-danger/16 px-2 text-xs font-semibold text-danger">
                    <x-ui.icon name="warning" :size="14" />{{ __('In the red') }}
                </span>
            @endif
        </div>
        <x-ui.amount :value="$forecast->expectedLeftover" size="hero" :tone="$negative ? 'danger' : 'accent'" class="mt-1.5" />
        <div class="num mt-2.5 text-sm text-muted">
            {{ __('Planned') }}: {{ money($forecast->plannedLeftover) }} · {{ trans_choice('{1} :count day left|[2,*] :count days left', $forecast->remainingDays, ['count' => $forecast->remainingDays]) }}
        </div>
    </section>

    @if ($negative)
        <div class="mx-4 mb-3 flex flex-col gap-3 rounded-[22px] border border-danger/28 bg-danger/10 px-4 pb-3.5 pt-4" data-test="negative-alert">
            <div class="flex items-start gap-3">
                <x-ui.icon name="error" :size="22" class="text-danger" />
                <div class="text-sm leading-[1.45] text-pretty text-ink-2">
                    <b class="font-semibold text-ink">{{ __(':amount over the plan.', ['amount' => money($forecast->plannedLeftover - $forecast->expectedLeftover)]) }}</b>
                    @if ($over !== [])
                        {{ trans_choice('{1} :names went over its budget – cut back elsewhere or cover it from the reserve.|[2,*] :names went over their budgets – cut back elsewhere or cover it from the reserve.', count($over), ['names' => implode(', ', array_map(fn ($c) => $c->categoryName, $over))]) }}
                    @else
                        {{ __('Cut back on spending or cover it from the reserve.') }}
                    @endif
                </div>
            </div>
            <div class="flex gap-2 pl-[34px]">
                <x-ui.button variant="light" size="sm" :href="route('pockets', ['fedezes' => max(0, -$forecast->expectedLeftover)])" wire:navigate data-test="cover-from-reserve">{{ __('Cover from the reserve') }}</x-ui.button>
                <x-ui.button variant="secondary" size="sm" :href="route('month')" wire:navigate class="!bg-ink/8">{{ __('Details') }}</x-ui.button>
            </div>
        </div>
    @endif

    @foreach ($this->pendingTransfers as $close)
        <div class="mx-4 mb-3 flex items-center gap-3 rounded-[22px] border border-accent/22 bg-accent/10 px-4 py-3.5" wire:key="transfer-{{ $close->id }}" data-test="pending-transfer">
            <x-ui.icon-tile icon="show_chart" tone="accent" />
            <div class="min-w-0 flex-1">
                <div class="num text-[15px] font-semibold">{{ __('Transfer :amount', ['amount' => money($close->to_invest)]) }}</div>
                <div class="truncate text-xs text-muted">{{ __('to :account, from the month-end leftover', ['account' => $close->surplusAccount->name ?? '']) }}</div>
            </div>
            <x-ui.button size="sm" variant="light" wire:click="markTransferred({{ $close->id }})" data-test="mark-transferred">{{ __('Transferred') }}</x-ui.button>
        </div>
    @endforeach

    @if ($period->isOpen())
        @foreach (array_filter($fixed, fn ($item) => ! $item->paid && $item->dueOn !== null && ! $item->dueOn->greaterThan($overview->today)) as $item)
            @php
                $isTransfer = in_array($item->line->type, [\App\Enums\LineType::Transfer, \App\Enums\LineType::Sinking], true);
                $late = $item->dueOn->lessThan($overview->today);
            @endphp
            <div class="mx-4 mb-3 flex items-center gap-3 rounded-[22px] border border-accent/22 bg-accent/10 px-4 py-3.5" wire:key="due-{{ $item->line->lineId }}" data-test="due-item">
                <x-ui.icon-tile icon="autorenew" tone="accent" />
                <div class="min-w-0 flex-1">
                    <div class="num truncate text-[15px] font-semibold">{{ $item->line->categoryName }} · {{ money($item->line->planned()) }}</div>
                    <div @class(['truncate text-xs', 'text-warn' => $late, 'text-muted' => ! $late])>{{ $late ? __('was due :date', ['date' => \App\Support\Dates::short($item->dueOn)]) : ($isTransfer ? __('Transfer due today') : __('Due today')) }}</div>
                </div>
                <x-ui.button size="sm" variant="light" wire:click="togglePaid({{ (int) $item->line->lineId }})" data-test="mark-due-done">{{ $isTransfer ? __('Transferred') : __('Done') }}</x-ui.button>
            </div>
        @endforeach
    @endif

    <button type="button" x-data x-on:click="$dispatch('open-entry')" class="mx-4 mb-3 flex w-[calc(100%-2rem)] items-center justify-between rounded-card bg-surface px-5 py-[18px] text-left" data-test="daily-allowance">
        <span>
            <span class="block text-[13px] text-muted">{{ __('You can still spend today') }}</span>
            <x-ui.amount :value="$negative ? 0 : $forecast->dailyAllowance" size="lg" :tone="$negative ? 'muted' : null" class="mt-1" />
            @if ($negative)
                <span class="mt-1.5 block text-xs text-muted">{{ __('Every further spending lowers the month-end leftover.') }}</span>
            @endif
        </span>
        <x-ui.icon name="chevron_right" :size="22" class="text-faint" />
    </button>

    @if (! $this->hasSpending)
        <x-ui.empty-state icon="receipt_long" :title="__('No spending recorded yet')" class="mx-4 mb-3" data-test="empty-state">
            {{ __('Your income and fixed items are already in. You only need to write down the variable spending – or tap once if you did not spend today.') }}
            <x-slot name="actions">
                <x-no-spend-button :marked="$overview->noSpendMarked" />
            </x-slot>
        </x-ui.empty-state>
    @endif

    @if ($this->hasSpending && $overview->spentToday === 0)
        <div class="mx-4 mb-3 flex justify-center">
            <x-no-spend-button :marked="$overview->noSpendMarked" />
        </div>
    @endif

    @if ($categories !== [])
        <x-ui.card class="mx-4 mb-3 px-[18px] pb-1.5 pt-[18px]" data-test="categories">
            <div class="flex items-baseline justify-between">
                <span class="text-[15px] font-semibold">{{ $this->hasSpending ? __('Variable spending') : __('Budgets') }}</span>
                <span @class(['num text-[13px]', 'text-danger' => $forecast->variableSpent > $forecast->variablePlanned, 'text-muted' => $forecast->variableSpent <= $forecast->variablePlanned])>
                    {{ money_number($forecast->variableSpent) }} / {{ money($forecast->variablePlanned) }}
                </span>
            </div>

            @foreach ($categories as $category)
                @php
                    $ratio = $category->planned > 0 ? $category->spent / $category->planned : ($category->spent > 0 ? 2 : 0);
                    $tone = $category->spent > $category->planned ? 'danger' : ($ratio >= 0.8 ? 'warn' : 'accent');
                @endphp
                <a href="{{ route('month', ['kategoria' => $category->categoryId]) }}" wire:navigate wire:key="category-{{ $category->categoryId }}"
                   @class(['grid grid-cols-[40px_minmax(0,1fr)] items-center gap-3 py-3', 'border-b border-line' => ! $loop->last])>
                    <x-ui.icon-tile :icon="$this->icons[$category->categoryId] ?? 'more_horiz'" />
                    <div>
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="truncate text-[15px] font-medium">{{ $category->categoryName }}</span>
                            <span class="num shrink-0 text-sm"><span class="font-semibold">{{ money_number($category->spent) }}</span><span class="text-muted"> / {{ money_number($category->planned) }} <span class="text-[0.8em]">{{ user_currency()->symbol() }}</span></span></span>
                        </div>
                        @if ($this->hasSpending)
                            <x-ui.bar :value="$category->planned > 0 ? $category->spent / $category->planned : ($category->spent > 0 ? 1 : 0)" :tone="$tone" class="mt-2" />
                            <div @class(['num mt-1.5 text-xs', 'text-danger' => $tone === 'danger', 'text-warn' => $tone === 'warn', 'text-muted' => $tone === 'accent'])>
                                @if ($category->spent > $category->planned)
                                    {{ __('+:amount over', ['amount' => money($category->spent - $category->planned)]) }}
                                @elseif ($category->planned > 0 && $category->spent === $category->planned)
                                    {{ __('Budget used up') }}
                                @else
                                    {{ __(':amount left', ['amount' => money($category->planned - $category->spent)]) }}
                                @endif
                            </div>
                        @endif
                    </div>
                </a>
            @endforeach
        </x-ui.card>
    @endif

    @if ($fixed !== [])
        <x-ui.card class="mx-4 mb-3 px-[18px] pb-2 pt-[18px]" data-test="fixed-items">
            <div class="mb-1 flex items-baseline justify-between">
                <span class="text-[15px] font-semibold">{{ __('Fixed items') }}</span>
                <span class="text-[13px] text-muted">{{ __('automatically in the plan') }}</span>
            </div>
            @foreach ($visibleFixed as $item)
                @php $overdue = $item->isOverdue($overview->today); @endphp
                <button type="button" wire:key="fixed-{{ $item->line->lineId }}" wire:click="togglePaid({{ (int) $item->line->lineId }})" @disabled(! $period->isOpen())
                        class="grid w-full grid-cols-[26px_minmax(0,1fr)_auto] items-center gap-3 border-b border-line py-[11px] text-left" data-test="fixed-item">
                    @if ($item->paid)
                        <span class="flex size-6 items-center justify-center rounded-full bg-accent/16 text-accent"><x-ui.icon name="check" :size="16" :weight="600" /></span>
                    @else
                        <span @class(['size-6 rounded-full border-[1.5px] border-dashed', 'border-warn' => $overdue, 'border-faint' => ! $overdue])></span>
                    @endif
                    <span class="min-w-0">
                        <span class="block truncate text-[15px]">{{ $item->line->categoryName }}</span>
                        <span @class(['block text-xs mt-0.5', 'text-warn' => $overdue, 'text-muted' => ! $overdue])>
                            @if ($item->paid)
                                {{ __('done') }}
                            @elseif ($item->dueOn)
                                {{ $overdue ? __('was due :date', ['date' => \App\Support\Dates::short($item->dueOn)]) : __('due :date', ['date' => \App\Support\Dates::short($item->dueOn)]) }}
                            @else
                                {{ $item->line->type->label() }}
                            @endif
                        </span>
                    </span>
                    <x-ui.amount size="sm" :value="$item->line->planned()" class="text-[15px] font-medium" />
                </button>
            @endforeach
            @if ($hiddenFixed !== [] && ! $this->showAllFixed)
                <button type="button" wire:click="$set('showAllFixed', true)" class="num flex w-full justify-between pb-2 pt-3 text-[13px] text-muted">
                    <span>{{ trans_choice('{1} +:count more item|[2,*] +:count more items', count($hiddenFixed), ['count' => count($hiddenFixed)]) }}</span>
                    <span>{{ money(array_sum(array_map(fn ($i) => $i->line->planned(), $hiddenFixed))) }}</span>
                </button>
            @else
                <div class="h-2"></div>
            @endif
        </x-ui.card>
    @endif

    <x-install-card class="mx-4 mb-3" />

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
       class="mx-4 mb-3 flex items-center gap-3 rounded-card bg-surface px-[18px] py-4" data-test="push-prompt">
        <x-ui.icon-tile icon="notifications" tone="accent" :fill="true" />
        <span class="min-w-0 flex-1">
            <span class="block text-[15px] font-medium">{{ __('Daily reminder') }}</span>
            <span class="block text-xs text-muted">{{ __('Notifications are off on this device. Turn them on so we can remind you in the evening.') }}</span>
        </span>
        <x-ui.icon name="chevron_right" :size="22" class="text-faint" />
    </a>
</div>
