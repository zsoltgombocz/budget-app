<?php

use App\Actions\Budget\ChangeFixedItemStatus;
use App\Actions\Budget\MarkNoSpendDay;
use App\Enums\LineStatus;
use App\Enums\PeriodMode;
use App\Models\Category;
use App\Models\User;
use App\Services\Data\ClosePreview;
use App\Services\Data\FixedItem;
use App\Services\Data\Overview;
use App\Services\OverviewService;
use App\Services\PeriodCloser;
use App\Support\Icons;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Today')] class extends Component {
    /** The "how it works" intro: once after onboarding, or reopened from Settings (?bemutato=1). */
    public bool $showIntro = false;

    public function mount(): void
    {
        $this->showIntro = $this->user()->settings()->needsIntro() || request()->boolean('bemutato');
    }

    public function dismissIntro(): void
    {
        $settings = $this->user()->settings();

        if ($settings->intro_seen_at === null) {
            $settings->update(['intro_seen_at' => now()]);
        }

        $this->showIntro = false;
    }

    /** How many open fixed items the checklist shows before "+N more"; done ones always show. */
    public const int FIXED_PREVIEW = 4;

    public bool $showAllFixed = false;

    /**
     * The fixed items already paid or skipped when the page was opened: they sit at the bottom
     * of the checklist, dimmed, and never fold away.
     *
     * @var list<int>
     */
    #[Locked]
    public array $doneAtLoad = [];

    /**
     * The fixed items' order when the page was opened, so a ticked item does not jump away.
     *
     * @var list<int>
     */
    #[Locked]
    public array $fixedOrder = [];

    /**
     * The fixed items in the order they had when the page was opened.
     *
     * @return list<FixedItem>
     */
    #[Computed]
    public function fixedItems(): array
    {
        $items = $this->overview->fixedItems;
        $ids = array_values(array_filter(array_map(fn (FixedItem $item): ?int => $item->line->lineId, $items), fn (?int $id): bool => $id !== null));

        if ($this->fixedOrder === []) {
            $this->doneAtLoad = array_values(array_filter(array_map(fn (FixedItem $item): ?int => $item->status() === LineStatus::Open ? null : $item->line->lineId, $items), fn (?int $id): bool => $id !== null));
        }

        $this->fixedOrder = $this->fixedOrder === []
            ? $ids
            : [...array_values(array_intersect($this->fixedOrder, $ids)), ...array_values(array_diff($ids, $this->fixedOrder))];

        $position = array_flip($this->fixedOrder);
        usort($items, fn (FixedItem $a, FixedItem $b): int => ($position[$a->line->lineId ?? -1] ?? PHP_INT_MAX) <=> ($position[$b->line->lineId ?? -1] ?? PHP_INT_MAX));

        return $items;
    }

    /**
     * Tick a fixed item off for the period, or untick it; the toast offers the way back.
     */
    public function togglePaid(int $budgetLineId): void
    {
        $this->fixedItemChanged(resolve(ChangeFixedItemStatus::class)->toggle($this->user(), $this->overview->period, $this->overview->fixedItems, $budgetLineId));
    }

    /**
     * Skip a plain fixed item this period: it counts as 0.
     */
    public function skipLine(int $budgetLineId): void
    {
        $this->fixedItemChanged(resolve(ChangeFixedItemStatus::class)->set($this->user(), $this->overview->period, $this->overview->fixedItems, $budgetLineId, LineStatus::Skipped));
    }

    /**
     * A skipped item comes up after all: back to due.
     */
    public function unskipLine(int $budgetLineId): void
    {
        $this->fixedItemChanged(resolve(ChangeFixedItemStatus::class)->set($this->user(), $this->overview->period, $this->overview->fixedItems, $budgetLineId, LineStatus::Open));
    }

    #[On(ChangeFixedItemStatus::UNDO_EVENT)]
    public function undoFixedItem(int $budgetLineId, string $status): void
    {
        if (resolve(ChangeFixedItemStatus::class)->undo($this->user(), $this->overview->period, $this->overview->fixedItems, $budgetLineId, $status)) {
            $this->refresh();
            $this->dispatch('budget-updated');
        }
    }

    /**
     * @param  array{title: string, subtitle: string|null, icon: string, undo: string, params: array{budgetLineId: int, status: string}}|null  $toast
     */
    private function fixedItemChanged(?array $toast): void
    {
        if ($toast === null) {
            return;
        }

        $this->refresh();
        $this->dispatch('budget-updated');
        $this->dispatch('app-toast', ...$toast);
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

        $this->dispatch('app-toast', title: __('Marked as transferred.'), icon: 'check_circle', undo: 'today-undo-transferred', params: ['periodCloseId' => $periodCloseId]);
    }

    #[On('today-undo-transferred')]
    public function undoTransferred(int $periodCloseId): void
    {
        $this->user()->periodCloses()->whereKey($periodCloseId)->update(['surplus_transferred_at' => null]);
        unset($this->pendingTransfers);
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
        unset($this->overview, $this->icons, $this->closePreview, $this->currentLeftover, $this->fixedItems);
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

    /**
     * Whether the plan has anything in it yet: an income or at least one line.
     * Without either (e.g. onboarding was skipped) nothing is "already in".
     */
    #[Computed]
    public function hasPlan(): bool
    {
        return $this->overview->period->income() > 0
            || $this->user()->budgetLines()->whereHas('category')->exists();
    }

    /**
     * The leftover as things stand: what closing today would leave (same figure as the Month
     * page and the closing wizard).
     */
    #[Computed]
    public function currentLeftover(): int
    {
        return $this->closePreview->leftover();
    }

    #[Computed]
    public function closePreview(): ClosePreview
    {
        return app(PeriodCloser::class)->preview($this->user(), $this->overview->period);
    }

    /**
     * Whether the period runs from payday to payday rather than to the end of the month.
     */
    #[Computed]
    public function isPaydayPeriod(): bool
    {
        return $this->user()->settings()->period_mode === PeriodMode::Payday;
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
    $current = $this->currentLeftover;
    $negative = $current < 0;
    $forecastNegative = $forecast->expectedLeftover < 0;
    $categories = $forecast->categories;
    $over = array_values(array_filter($categories, fn ($c) => $c->isOver()));
    $dailyShown = $forecastNegative ? 0 : $forecast->dailyAllowance;
    // The part of the variable budgets not spent yet: it is in the big number, not in "if you spend every budget".
    $unspentBudget = $forecast->variableExpected - $forecast->variableSpent;

    // The checklist keeps the order the page opened with. Items already done then sit at the bottom
    // and stay visible (dimmed); only further open items fold away behind "+N".
    $fixed = $this->fixedItems;
    $openPositions = 0;
    $visibleFixed = [];
    $hiddenFixed = [];
    foreach ($fixed as $item) {
        $isOpenAtLoad = ! in_array($item->line->lineId, $this->doneAtLoad, true);
        if ($this->showAllFixed || ! $isOpenAtLoad || $openPositions < $this::FIXED_PREVIEW) {
            $visibleFixed[] = $item;
        } else {
            $hiddenFixed[] = $item;
        }
        $openPositions += $isOpenAtLoad ? 1 : 0;
    }
    $dueItems = $period->isOpen() ? array_values(array_filter($fixed, fn ($item) => $item->isOverdue($overview->today))) : [];

    // The period's own numbers for the "how is this calculated" sheets.
    $minus = fn (int $amount): string => $amount > 0 ? '−'.money($amount) : money(0);
    $preview = $this->closePreview;
    $previewRows = collect($preview->categories);
    $variableType = \App\Enums\LineType::Variable->value;
    $fixedPlanned = (int) $previewRows->where('type', '!=', $variableType)->sum('planned');
    $skippedTotal = (int) $previewRows->where('type', '!=', $variableType)->where('skipped', true)->sum(fn ($row) => $row['planned'] - $row['actual']);
    $pocketRows = array_filter([
        $forecast->pocketWithdrawals > 0 ? [__('From pockets into the budget'), '+'.money($forecast->pocketWithdrawals)] : null,
        $forecast->pocketDeposits > 0 ? [__('Put into pockets'), '−'.money($forecast->pocketDeposits)] : null,
    ]);
    $fixedRows = array_filter([
        [__('Fixed items, transfers, installments, savings'), $minus($fixedPlanned)],
        $skippedTotal > 0 ? [__('Skipped this month'), '+'.money($skippedTotal)] : null,
    ]);
    $leftoverRows = [
        [__('Income'), money($preview->incomeActual)],
        ...$pocketRows,
        ...$fixedRows,
        [__('Variable spending so far'), $minus((int) $previewRows->where('type', $variableType)->sum('actual'))],
        [__('Leftover as things stand'), money($current)],
    ];
    $ifSpentRows = [
        [__('Income'), money($forecast->baseIncome)],
        ...$pocketRows,
        ...$fixedRows,
        [__('Variable budgets (or spending, where more)'), $minus($forecast->variableExpected)],
        [__('If you spend every budget in full'), money($forecast->expectedLeftover)],
    ];
    $dailyRows = [
        [__('Variable budgets'), money($forecast->variablePlanned)],
        ...$pocketRows,
        [__('Spent so far'), $minus($forecast->variableSpent)],
        [__('Days left, today included'), trans_choice('{1} :count day|[2,*] :count days', $forecast->remainingDays, ['count' => $forecast->remainingDays])],
        [__('Per day'), money($dailyShown)],
    ];
@endphp

<div @class(['min-h-dvh', 'bg-[radial-gradient(120%_40%_at_30%_0%,rgba(242,85,90,0.12),rgba(242,85,90,0)_70%)]' => $negative])>
    <h1 class="sr-only">{{ __('Today') }}</h1>

    <div class="flex items-center justify-between px-5 pt-6">
        <div class="flex h-[34px] items-center gap-2 rounded-full bg-surface px-3.5 text-[13px] font-medium text-ink-2" data-test="period-chip">
            <span @class(['size-1.5 rounded-full', 'bg-danger' => $negative, 'bg-accent' => ! $negative])></span>
            <span class="num">{{ \App\Support\Dates::range($period->starts_on, $period->ends_on) }} · {{ __(':day. day', ['day' => max(1, $period->elapsedDays($overview->today))]) }}</span>
        </div>
    </div>

    <section class="px-6 pb-[22px] pt-[30px]" data-test="expected-leftover">
        <div class="flex items-center gap-1 text-sm text-muted">
            <span>{{ __('Leftover as things stand') }}</span>
            <x-explainer topic="leftover" :rows="$leftoverRows" />
            @if ($negative)
                <span class="inline-flex h-[22px] items-center gap-1 rounded-full bg-danger/16 px-2 text-xs font-semibold text-danger">
                    <x-ui.icon name="warning" :size="14" />{{ __('In the red') }}
                </span>
            @endif
        </div>
        <x-ui.amount :value="$current" size="hero" :tone="$negative ? 'danger' : 'accent'" class="mt-1.5" />
        @if ($unspentBudget > 0)
            <p class="num mt-2 text-xs text-pretty text-muted" data-test="unspent-budget">{{ __('This includes your budget not spent yet: :amount', ['amount' => money($unspentBudget)]) }}</p>
        @endif
        <div class="num mt-3 text-[15px] font-medium" data-test="leftover-if-spent">
            {{-- The amount and the "i" stay together, so the icon never drops to a line of its own. --}}
            @php [$ifSpentLabel] = explode('@@', __('If you spend every budget in full: :amount', ['amount' => '@@']), 2); @endphp
            <span @class(['text-danger' => $forecastNegative, 'text-ink' => ! $forecastNegative])>{{ $ifSpentLabel }}<span class="whitespace-nowrap">{{ money($forecast->expectedLeftover) }}<x-explainer topic="if-spent" :rows="$ifSpentRows" /></span></span>
        </div>
        <div class="num mt-0.5 text-sm text-muted">{{ trans_choice('{1} :count day left|[2,*] :count days left', $forecast->remainingDays, ['count' => $forecast->remainingDays]) }}</div>
    </section>

    {{-- "I didn't spend today" sits at the bottom of this card while nothing is recorded for today: always visible, undoable in place. --}}
    <x-ui.card @class(['mx-4 mb-3 px-5 pt-[18px]', 'pb-[18px]' => $overview->spentToday > 0, 'pb-1' => $overview->spentToday === 0]) data-test="daily-allowance">
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <div class="flex items-center gap-1 text-[13px] text-muted">
                    <span>{{ __('You can still spend today') }}</span>
                    <x-explainer topic="daily" :rows="$dailyRows" />
                </div>
                <x-ui.amount :value="$dailyShown" size="lg" :tone="$forecastNegative ? 'muted' : null" class="mt-1" />
            </div>
            <x-ui.button variant="tonal" size="md" icon="add" x-data x-on:click="$dispatch('open-entry')" class="shrink-0 max-[360px]:px-3" :aria-label="__('Record')" data-test="daily-record"><span class="max-[360px]:sr-only">{{ __('Record') }}</span></x-ui.button>
        </div>
        @if ($forecastNegative)
            <p class="mt-1.5 text-[13px] leading-snug text-muted">{{ $this->isPaydayPeriod ? __('Every further spending lowers the leftover at payday.') : __('Every further spending lowers the month-end leftover.') }}</p>
        @endif
        @if ($overview->spentToday === 0)
            <div class="mt-3.5 border-t border-line py-1 [&>[data-test=no-spend]]:-ml-3">
                <x-no-spend-button :marked="$overview->noSpendMarked" />
            </div>
        @endif
    </x-ui.card>

    @if ($forecastNegative)
        <x-ui.callout tone="danger" :title="__(':amount over the plan.', ['amount' => money($forecast->plannedLeftover - $forecast->expectedLeftover)])" class="mx-4 mb-3" data-test="negative-alert">
            @if ($over !== [])
                {{ trans_choice('{1} :names went over its budget – cut back elsewhere or cover it from the reserve.|[2,*] :names went over their budgets – cut back elsewhere or cover it from the reserve.', count($over), ['names' => implode(', ', array_map(fn ($c) => $c->categoryName, $over))]) }}
            @else
                {{ __('Cut back on spending or cover it from the reserve.') }}
            @endif
            <x-slot:actions>
                <x-ui.button variant="secondary" size="sm" :href="route('pockets', ['fedezes' => max(0, -$forecast->expectedLeftover)])" wire:navigate data-test="cover-from-reserve">{{ __('Cover from the reserve') }}</x-ui.button>
                <x-ui.button variant="text" size="sm" :href="route('month')" wire:navigate>{{ __('Details') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.callout>
    @endif

    @foreach ($this->pendingTransfers as $close)
        <x-ui.card class="mx-4 mb-3 flex flex-wrap items-center gap-x-3 gap-y-2 px-[18px] py-3.5" wire:key="transfer-{{ $close->id }}" data-test="pending-transfer">
            <div class="flex min-w-[min(100%,11rem)] flex-1 items-center gap-3">
                <x-ui.icon-tile icon="show_chart" tone="accent" />
                <div class="min-w-0 flex-1">
                    <div class="num text-[15px] font-semibold">{{ __('Transfer :amount', ['amount' => money($close->to_invest)]) }}</div>
                    <div class="truncate text-[13px] text-muted">{{ __('to :account, from the month-end leftover', ['account' => $close->surplusAccount->name ?? '']) }}</div>
                </div>
            </div>
            <x-ui.button size="sm" variant="tonal" icon="check" wire:click="markTransferred({{ $close->id }})" class="ml-auto shrink-0" data-test="mark-transferred">{{ __('Transferred') }}</x-ui.button>
        </x-ui.card>
    @endforeach

    {{-- Fixed items due today or overdue: one folded row, the items with their actions open from it. --}}
    @if ($dueItems !== [])
        <x-ui.card class="mx-4 mb-3 px-[18px] py-1" x-data="{ showDue: false }" data-test="due-items">
            <button type="button" x-on:click="showDue = ! showDue" :aria-expanded="showDue ? 'true' : 'false'" aria-expanded="false" aria-controls="today-due-list"
                    class="focus-ring -mx-1 flex min-h-[60px] w-[calc(100%+0.5rem)] items-center gap-3 rounded-xl px-1 text-left" data-test="due-summary">
                <x-ui.icon-tile icon="schedule" tone="warn" />
                <span class="num min-w-0 flex-1 text-[15px] font-semibold">{{ trans_choice('{1} :count fixed item due|[2,*] :count fixed items due', count($dueItems), ['count' => count($dueItems)]) }}</span>
                <span class="flex shrink-0 items-center gap-0.5 text-sm font-medium text-ink-2">
                    <span class="max-[360px]:sr-only" x-text="showDue ? @js(__('Hide')) : @js(__('Show me'))">{{ __('Show me') }}</span>
                    <x-ui.icon name="expand_more" :size="20" class="text-muted transition-transform" x-bind:class="showDue && 'rotate-180'" />
                </span>
            </button>
            <div id="today-due-list" x-show="showDue" x-cloak class="pb-2">
                @foreach ($dueItems as $item)
                    @php
                        $isTransfer = in_array($item->line->type, [\App\Enums\LineType::Transfer, \App\Enums\LineType::Sinking], true);
                        $late = $item->dueOn->lessThan($overview->today);
                    @endphp
                    <div class="border-t border-line py-3" wire:key="due-{{ $item->line->lineId }}" data-test="due-item">
                        {{-- The list row anatomy: status ring, name over the due date, the amount right. Only the name gives way on a narrow screen. --}}
                        <div class="grid grid-cols-[24px_minmax(0,1fr)_auto] items-center gap-x-3">
                            <span class="row-span-2 size-6 rounded-full border-[1.5px] border-warn" aria-hidden="true"></span>
                            <span class="truncate text-[15px] font-medium">{{ $item->line->categoryName }}</span>
                            <x-ui.amount size="sm" :value="$item->line->planned()" class="row-span-2 text-[15px] font-semibold" data-test="due-amount" />
                            <span class="mt-0.5 truncate text-xs text-warn">{{ $late ? __('was due :date', ['date' => \App\Support\Dates::short($item->dueOn)]) : ($isTransfer ? __('Transfer due today') : __('Due today')) }}</span>
                        </div>
                        <div class="mt-2.5 flex flex-wrap gap-1 pl-9">
                            <x-ui.button size="sm" variant="tonal" icon="check" wire:click="togglePaid({{ (int) $item->line->lineId }})" data-test="mark-due-done">{{ $isTransfer ? __('Transferred') : __('Done') }}</x-ui.button>
                            @if ($item->line->isSkippable())
                                <x-ui.button size="sm" variant="text" wire:click="skipLine({{ (int) $item->line->lineId }})" data-test="skip-due">{{ __('Skipped this month') }}</x-ui.button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endif

    @if (! $this->hasSpending && ! $this->hasPlan)
        <x-ui.empty-state icon="edit_note" :title="__('Start with your plan')" class="mx-4 mb-3" data-test="empty-state-no-plan">
            {{ __('Your plan is still empty. Add your monthly income and your fixed items on the Plan screen; then you only need to write down the variable spending here.') }}
            <x-slot name="actions">
                <x-ui.button size="md" variant="tonal" :href="route('plan')" wire:navigate>{{ __('Open the plan') }}</x-ui.button>
            </x-slot>
        </x-ui.empty-state>
    @elseif (! $this->hasSpending)
        <x-ui.empty-state icon="receipt_long" :title="__('No spending recorded yet')" class="mx-4 mb-3" data-test="empty-state">
            {{ __('Your income and fixed items are already in. Record the variable spending with the + button – or mark the day above if you did not spend.') }}
        </x-ui.empty-state>
    @endif

    @if ($categories !== [])
        @php
            $unbudgeted = array_values(array_filter($categories, fn ($category): bool => $category->planned <= 0));
            $budgetLineIds = $unbudgeted !== [] ? auth()->user()?->budgetLines()->pluck('id', 'category_id') ?? collect() : collect();
        @endphp
        <x-ui.card class="mx-4 mb-3 px-[18px] pb-1.5 pt-[18px]" data-test="categories">
            <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                <h2 class="text-[15px] font-semibold">{{ __('Variable spending') }}</h2>
                <span @class(['num ml-auto shrink-0 text-[13px]', 'text-danger' => $forecast->variableSpent > $forecast->variablePlanned, 'text-muted' => $forecast->variableSpent <= $forecast->variablePlanned])>
                    {{ money_number($forecast->variableSpent) }} / {{ money($forecast->variablePlanned) }}
                </span>
            </div>

            {{-- One quiet note for every category without a budget, instead of a link under each of them. --}}
            @if ($unbudgeted !== [])
                <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-group bg-surface-2 py-2 pl-3.5 pr-2" data-test="no-budget-note">
                    <span class="min-w-[10rem] flex-1 text-[13px] leading-snug text-muted">{{ trans_choice('{1} :names has no budget yet.|[2,*] :count categories have no budget yet.', count($unbudgeted), ['count' => count($unbudgeted), 'names' => $unbudgeted[0]->categoryName]) }}</span>
                    <x-ui.button variant="tonal" size="sm" :href="route('plan', array_filter(['tetel' => count($unbudgeted) === 1 ? $budgetLineIds->get($unbudgeted[0]->categoryId) : null]))" wire:navigate class="ml-auto shrink-0" data-test="set-budget">{{ __('Set a budget') }}</x-ui.button>
                </div>
            @endif

            @foreach ($categories as $category)
                @php
                    $noBudget = $category->planned <= 0;
                    $ratio = $noBudget ? 0 : $category->spent / $category->planned;
                    // Red only for going over a budget, amber from 80%; a category without a budget stays neutral.
                    $tone = match (true) {
                        $noBudget => 'muted',
                        $category->spent > $category->planned => 'danger',
                        $ratio >= \App\Services\Data\CategoryForecast::WARNING_RATIO => 'warn',
                        default => 'accent',
                    };
                @endphp
                <div wire:key="category-{{ $category->categoryId }}" @class(['border-b border-line' => ! $loop->last]) data-test="category-row">
                    <a href="{{ route('month', ['kategoria' => $category->categoryId]) }}" wire:navigate
                       class="focus-ring grid grid-cols-[40px_minmax(0,1fr)_18px] items-center gap-3 rounded-xl py-3">
                        <x-ui.icon-tile :icon="$this->icons[$category->categoryId] ?? \App\Support\Icons::FALLBACK" />
                        <div class="min-w-0">
                            {{-- The amount wraps under the name rather than squeezing it on a narrow phone. --}}
                            <div class="flex flex-wrap items-baseline justify-between gap-x-2">
                                <span class="min-w-0 truncate text-[15px] font-medium">{{ $category->categoryName }}</span>
                                @if ($noBudget)
                                    <x-ui.amount size="sm" :value="$category->spent" class="ml-auto shrink-0 text-sm [&>span:first-child]:font-semibold" />
                                @else
                                    <x-ui.amount size="sm" :value="$category->spent" :of="$category->planned" class="ml-auto shrink-0 text-sm [&>span:first-child]:font-semibold" />
                                @endif
                            </div>
                            @if ($noBudget)
                                <div class="mt-0.5 text-xs text-muted" data-test="no-budget">{{ __('no budget') }}</div>
                            @elseif ($this->hasSpending)
                                <x-ui.bar :label="$category->categoryName" :value="$ratio" :tone="$tone" class="mt-2" />
                                <div @class(['num mt-1.5 text-xs', 'text-danger' => $tone === 'danger', 'text-warn' => $tone === 'warn', 'text-muted' => $tone === 'accent'])>
                                    @if ($category->spent > $category->planned)
                                        {{ __('+:amount over', ['amount' => money($category->spent - $category->planned)]) }}
                                    @elseif ($category->spent === $category->planned)
                                        {{ __('Budget used up') }}
                                    @else
                                        {{ __(':amount left', ['amount' => money($category->planned - $category->spent)]) }}
                                    @endif
                                </div>
                            @endif
                        </div>
                        <x-ui.icon name="chevron_right" :size="18" class="text-faint" />
                    </a>
                </div>
            @endforeach
        </x-ui.card>
    @endif

    {{-- The fixed items to tick off, in a stable order while the page is open. --}}
    @if ($fixed !== [])
        <x-ui.card class="mx-4 mb-3 px-[18px] pb-1.5 pt-[18px]" data-test="fixed-items">
            <div class="mb-1">
                <h2 class="text-[15px] font-semibold">{{ __('Fixed items') }}</h2>
                <p class="mt-0.5 text-[13px] text-muted">{{ __('already in the plan, no need to record them') }}</p>
            </div>
            @foreach ($visibleFixed as $item)
                <x-fixed-item-row :item="$item" :today="$overview->today" :open="$period->isOpen()" @class(['border-b border-line' => ! $loop->last || $hiddenFixed !== []]) wire:key="fixed-{{ $item->line->lineId }}" />
            @endforeach
            @if ($hiddenFixed !== [])
                <x-ui.button variant="text" size="md" icon-trailing="arrow_forward" wire:click="$set('showAllFixed', true)" class="-ml-3 mb-1" data-test="fixed-more">{{ __('All items (:count)', ['count' => count($fixed)]) }}</x-ui.button>
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

    <x-fixed-item-sheet />

    <x-how-it-works :open="$showIntro" />
</div>
