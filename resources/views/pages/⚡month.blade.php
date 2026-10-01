<?php

use App\Models\Category;
use App\Models\Period;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PeriodService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
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
        $this->user()->transactions()->whereKey($transactionId)->delete();
        unset($this->transactions);

        Flux::toast(text: __('Entry removed.'));
    }

    public function updatedPeriodId(): void
    {
        unset($this->period, $this->transactions);
    }

    #[Computed]
    public function period(): Period
    {
        return $this->user()->periods()->findOrFail($this->periodId);
    }

    /**
     * @return Collection<int, Period>
     */
    #[Computed]
    public function periods(): Collection
    {
        return $this->user()->periods()->orderByDesc('starts_on')->get();
    }

    /**
     * @return Collection<int, Transaction>
     */
    #[Computed]
    public function transactions(): Collection
    {
        return $this->period->transactions()
            ->with('category')
            ->when($this->categoryId, fn ($query, int $categoryId) => $query->where('category_id', $categoryId))
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->get();
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

<div class="flex flex-col gap-4">
    <div class="flex items-center gap-2">
        <flux:select wire:model.live="periodId" class="flex-1" :aria-label="__('Period')">
            @foreach ($this->periods as $period)
                <flux:select.option :value="$period->id">
                    {{ $period->starts_on->isoFormat('YYYY. MM. DD.') }} – {{ $period->ends_on->isoFormat('MM. DD.') }}
                    @if (! $period->isOpen()) · {{ __('closed') }} @endif
                </flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($this->period->isOpen())
        <flux:button :href="route('close', $this->period)" wire:navigate icon="lock-closed" data-test="start-close">
            {{ __('Close the period') }}
        </flux:button>
    @elseif ($close = $this->period->close()->first())
        <a href="{{ route('close', $this->period) }}" wire:navigate class="block rounded-2xl bg-white p-4 shadow-xs dark:bg-zinc-800" data-test="close-summary">
            <div class="flex items-center justify-between">
                <flux:heading>{{ __('Closed') }}</flux:heading>
                <flux:icon.chevron-right class="size-4 text-zinc-400" />
            </div>
            <dl class="mt-2 grid grid-cols-3 gap-2 text-xs">
                <div><dt class="text-zinc-500">{{ __('Leftover') }}</dt><dd><x-money :amount="$close->leftover" class="text-sm font-semibold" /></dd></div>
                <div><dt class="text-zinc-500">{{ __('To the reserve') }}</dt><dd><x-money :amount="$close->to_reserve" class="text-sm" /></dd></div>
                <div><dt class="text-zinc-500">{{ __('Rest') }}</dt><dd><x-money :amount="$close->to_invest" class="text-sm" /></dd></div>
            </dl>
        </a>
    @endif

    @if ($this->category)
        <div class="flex items-center gap-2">
            <flux:badge icon="funnel">{{ $this->category->name }}</flux:badge>
            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="$set('categoryId', null)" :aria-label="__('Clear filter')" />
        </div>
    @endif

    <div class="flex items-baseline justify-between">
        <flux:heading>{{ __('Spending') }}</flux:heading>
        <x-money :amount="$this->transactions->sum('amount')" class="font-semibold" />
    </div>

    @if ($this->transactions->isEmpty())
        <flux:callout icon="receipt-percent">
            <flux:callout.heading>{{ __('No spending recorded') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Tap the + button to record what you spend. Fixed costs are already part of the plan.') }}</flux:callout.text>
        </flux:callout>
    @else
        @foreach ($this->transactions->groupBy(fn ($transaction) => $transaction->occurred_on->toDateString()) as $day => $transactions)
            <section wire:key="day-{{ $day }}" class="flex flex-col gap-1">
                <div class="flex justify-between px-1 text-xs font-medium uppercase tracking-wide text-zinc-500">
                    <span>{{ \Carbon\CarbonImmutable::parse($day)->locale(app()->getLocale())->isoFormat('MMMM D., dddd') }}</span>
                    <x-money :amount="$transactions->sum('amount')" />
                </div>
                <ul class="divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
                    @foreach ($transactions as $transaction)
                        <li wire:key="transaction-{{ $transaction->id }}" class="flex items-center gap-3 px-3 py-2">
                            @if ($transaction->category)
                                <x-category-icon :category="$transaction->category" class="size-5 shrink-0" />
                            @endif
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium">{{ $transaction->category?->name }}</div>
                                @if ($transaction->note)
                                    <div class="truncate text-xs text-zinc-500">{{ $transaction->note }}</div>
                                @endif
                            </div>
                            <x-money :amount="$transaction->amount" class="text-sm font-medium" />
                            @if ($this->period->isOpen())
                                <flux:button size="xs" variant="ghost" icon="trash" wire:click="delete({{ $transaction->id }})" wire:confirm="{{ __('Delete this entry?') }}" :aria-label="__('Delete')" />
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    @endif
</div>
