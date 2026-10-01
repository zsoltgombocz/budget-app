<?php

use App\Actions\Budget\MarkNoSpendDay;
use App\Actions\Budget\ToggleLinePaid;
use App\Models\User;
use App\Services\Data\Overview;
use App\Services\OverviewService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Today')] class extends Component {
    public const int TOP_CATEGORIES = 4;

    public function togglePaid(int $budgetLineId): void
    {
        app(ToggleLinePaid::class)->handle($this->user(), $this->overview->period, $budgetLineId);
        unset($this->overview);
    }

    public function markNoSpend(): void
    {
        app(MarkNoSpendDay::class)->handle($this->user());
        unset($this->overview);
    }

    public function saveReminderTime(string $time): void
    {
        validator(['time' => $time], ['time' => ['required', 'date_format:H:i']])->validate();

        $this->user()->settings()->update(['reminder_time' => $time, 'reminder_enabled' => true]);
    }

    #[Computed]
    public function overview(): Overview
    {
        return app(OverviewService::class)->forUser($this->user());
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
    $categories = $overview->categoriesByUrgency();
    $difference = $forecast->expectedLeftover - $forecast->plannedLeftover;
@endphp

<div class="flex flex-col gap-4">
    <x-push-prompt :reminder-time="substr(auth()->user()->settings()->reminder_time, 0, 5)" />

    <section class="rounded-2xl bg-zinc-900 p-4 text-white shadow-sm dark:bg-white dark:text-zinc-900" data-test="expected-leftover">
        <div class="flex items-center justify-between gap-2 text-sm opacity-80">
            <span class="truncate">{{ __('Expected leftover at period end') }}</span>
            <span class="shrink-0 whitespace-nowrap text-xs">{{ $overview->period->starts_on->isoFormat('MM. DD.') }} – {{ $overview->period->ends_on->isoFormat('MM. DD.') }}</span>
        </div>
        <x-money :amount="$forecast->expectedLeftover" @class([
            'mt-1 block text-4xl font-semibold tracking-tight',
            'text-red-400 dark:text-red-600' => $forecast->expectedLeftover < 0,
        ]) />
        <div class="mt-1 flex flex-wrap items-center gap-x-2 text-sm opacity-80">
            <span>{{ __('Planned') }}: <x-money :amount="$forecast->plannedLeftover" /></span>
            @if ($difference !== 0)
                <span @class(['font-medium', 'text-red-300 dark:text-red-600' => $difference < 0, 'text-emerald-300 dark:text-emerald-600' => $difference > 0])>
                    (<x-money :amount="$difference" signed />)
                </span>
            @endif
        </div>
    </section>

    <section class="grid grid-cols-3 gap-2 text-center">
        <div class="rounded-xl bg-white p-2 shadow-xs dark:bg-zinc-800">
            <div class="text-lg font-semibold tabular-nums">{{ $forecast->remainingDays }}</div>
            <div class="text-xs text-zinc-500">{{ __('days left') }}</div>
        </div>
        <div class="rounded-xl bg-white p-2 shadow-xs dark:bg-zinc-800">
            <x-money :amount="$forecast->dailyAllowance" class="block text-lg font-semibold" />
            <div class="text-xs text-zinc-500">{{ __('per day') }}</div>
        </div>
        <div class="rounded-xl bg-white p-2 shadow-xs dark:bg-zinc-800">
            <x-money :amount="$overview->spentToday" class="block text-lg font-semibold" />
            <div class="text-xs text-zinc-500">{{ __('spent today') }}</div>
        </div>
    </section>

    <section class="flex flex-col gap-2" data-test="categories">
        <div class="flex items-baseline justify-between">
            <flux:heading>{{ __('Budgets') }}</flux:heading>
            <flux:text class="text-sm"><x-money :amount="$forecast->variableSpent" /> / <x-money :amount="$forecast->variablePlanned" /></flux:text>
        </div>

        @if ($categories === [])
            <flux:callout icon="information-circle">
                <flux:callout.text>{{ __('Add variable budgets on the Plan screen to track your spending here.') }}</flux:callout.text>
            </flux:callout>
        @else
            <div class="flex flex-col gap-1 rounded-xl bg-white p-2 shadow-xs dark:bg-zinc-800">
                @foreach (array_slice($categories, 0, $this::TOP_CATEGORIES) as $category)
                    <x-category-progress :category="$category" wire:key="category-{{ $category->categoryId }}" />
                @endforeach

                @if (count($categories) > $this::TOP_CATEGORIES)
                    <details class="group">
                        <summary class="cursor-pointer list-none px-2 py-1 text-center text-xs font-medium text-zinc-500">
                            <span class="group-open:hidden">{{ __('Show :count more', ['count' => count($categories) - $this::TOP_CATEGORIES]) }}</span>
                            <span class="hidden group-open:inline">{{ __('Show less') }}</span>
                        </summary>
                        @foreach (array_slice($categories, $this::TOP_CATEGORIES) as $category)
                            <x-category-progress :category="$category" wire:key="category-{{ $category->categoryId }}" />
                        @endforeach
                    </details>
                @endif
            </div>
        @endif
    </section>

    @if ($overview->spentToday === 0 && ! $overview->noSpendMarked)
        <flux:button wire:click="markNoSpend" icon="hand-thumb-up" class="w-full" data-test="no-spend">
            {{ __("I didn't spend today") }}
        </flux:button>
    @endif

    <section class="flex flex-col gap-2" data-test="fixed-items">
        <flux:heading>{{ __('Fixed items') }}</flux:heading>

        @if ($overview->fixedItems === [])
            <flux:text class="text-sm">{{ __('No fixed items in the plan.') }}</flux:text>
        @else
            <ul class="divide-y divide-zinc-200 overflow-hidden rounded-xl bg-white shadow-xs dark:divide-zinc-700 dark:bg-zinc-800">
                @foreach ($overview->fixedItems as $item)
                    <li wire:key="fixed-{{ $item->line->lineId }}">
                        <label class="flex cursor-pointer items-center gap-3 px-3 py-2">
                            <input type="checkbox" class="size-5 rounded accent-emerald-600"
                                   @checked($item->paid)
                                   @disabled(! $overview->period->isOpen())
                                   wire:click="togglePaid({{ (int) $item->line->lineId }})">
                            <span class="min-w-0 flex-1">
                                <span @class(['block truncate text-sm font-medium', 'text-zinc-400 line-through' => $item->paid])>{{ $item->line->categoryName }}</span>
                                <span @class(['block text-xs', 'text-red-600 dark:text-red-400' => $item->isOverdue($overview->today), 'text-zinc-500' => ! $item->isOverdue($overview->today)])>
                                    {{ $item->line->type->label() }}@if ($item->dueOn) · {{ $item->paid ? __('paid') : __('due :date', ['date' => $item->dueOn->isoFormat('MM. DD.')]) }}@endif
                                </span>
                            </span>
                            <x-money :amount="$item->line->planned()" class="text-sm" />
                        </label>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
