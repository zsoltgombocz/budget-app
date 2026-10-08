<?php

use App\Actions\Capture\RecategorizeTransaction;
use App\Actions\Capture\ResolveCapture;
use App\Enums\CaptureStatus;
use App\Enums\LineType;
use App\Enums\TransactionSource;
use App\Models\PaymentCapture;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PeriodService;
use App\Support\Icons;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * Today screen: automatically captured payments that wait for a decision, and today's
 * automatic spending with one-tap category change and delete.
 */
new class extends Component {
    /** Newest capture this screen knows about; a newer one refreshes the whole screen. */
    public int $lastCaptureId = 0;

    public function mount(): void
    {
        $this->lastCaptureId = $this->latestCaptureId();
    }

    /**
     * Polled while the screen is open, so a payment made meanwhile shows up and the leftover updates.
     */
    public function checkForNew(): void
    {
        $latest = $this->latestCaptureId();

        if ($latest !== $this->lastCaptureId) {
            $this->lastCaptureId = $latest;
            $this->refresh();
            $this->dispatch('budget-updated');
        } else {
            $this->skipRender();
        }
    }

    /**
     * @param  int|null  $amount  Whole units of the base currency, typed on the numpad.
     */
    public function record(int $captureId, ?int $amount = null): void
    {
        $user = $this->user();
        $minor = $amount === null ? null : $amount * $user->settings()->currency->minorPerMajor();

        app(ResolveCapture::class)->record($user, $captureId, $minor);
        $this->changed(__('Recorded.'), 'check_circle');
    }

    public function applyRefund(int $captureId): void
    {
        app(ResolveCapture::class)->applyRefund($this->user(), $captureId);
        $this->changed(__('Refund taken off the spending.'), 'undo');
    }

    public function dismiss(int $captureId): void
    {
        app(ResolveCapture::class)->dismiss($this->user(), $captureId);
        $this->changed(__('Dismissed, it does not count.'), 'close');
    }

    public function recategorize(int $transactionId, int $categoryId): void
    {
        $transaction = app(RecategorizeTransaction::class)->handle($this->user(), $transactionId, $categoryId);

        $this->changed(filled($transaction->merchant)
            ? __(':merchant goes to :category from now on.', ['merchant' => $transaction->merchant, 'category' => $transaction->category->name])
            : __('Moved to :category.', ['category' => $transaction->category->name]), 'category');
    }

    public function delete(int $transactionId): void
    {
        $this->user()->transactions()->whereKey($transactionId)->whereRelation('period', 'status', 'open')->first()?->delete();
        $this->changed(__('Entry removed.'), 'delete');
    }

    /**
     * @return Collection<int, PaymentCapture>
     */
    #[Computed]
    public function pending(): Collection
    {
        return $this->user()->paymentCaptures()->with('relatedTransaction.category')
            ->where('status', CaptureStatus::Pending)->latest('occurred_at')->get();
    }

    /**
     * @return Collection<int, Transaction>
     */
    #[Computed]
    public function recent(): Collection
    {
        $today = app(PeriodService::class)->today($this->user()->settings());

        return $this->user()->transactions()->with('category')
            ->where('source', TransactionSource::Auto)
            ->whereDate('occurred_on', $today->toDateString())
            ->latest('id')->limit(10)->get();
    }

    /**
     * @return list<array{id: int, name: string, icon: string}>
     */
    #[Computed]
    public function categories(): array
    {
        return $this->user()->categories()->where('type', LineType::Variable)->orderBy('sort')->orderBy('name')->get()
            ->map(fn ($category): array => ['id' => $category->id, 'name' => $category->name, 'icon' => Icons::forCategory($category->icon)])
            ->values()->all();
    }

    /**
     * Only users who set up a phone wait for payments to arrive.
     */
    #[Computed]
    public function polling(): bool
    {
        return $this->user()->captureTokens()->exists();
    }

    #[On('budget-updated')]
    public function refresh(): void
    {
        unset($this->pending, $this->recent, $this->categories);
    }

    private function latestCaptureId(): int
    {
        return (int) $this->user()->paymentCaptures()->max('id');
    }

    private function changed(string $title, string $icon): void
    {
        $this->refresh();
        $this->dispatch('budget-updated');
        $this->dispatch('app-toast', title: $title, icon: $icon);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $currency = user_currency();
    $reasonIcons = [
        'foreign_currency' => 'payments',
        'possible_duplicate' => 'done_all',
        'refund' => 'undo',
        'period_closed' => 'event_note',
        'no_category' => 'category',
    ];
@endphp

<div x-data="{
        selected: null,
        pad: null,
        digits: '',
        format: new Intl.NumberFormat(@js(str_replace('_', '-', app()->getLocale())), { maximumFractionDigits: 0, useGrouping: 'always' }),
        press(key) {
            if (key === 'del') { this.digits = this.digits.slice(0, -1); return }
            if (key === '000') { if (this.digits) this.digits = (this.digits + '000').slice(0, 9); return }
            if (this.digits === '' && key === '0') return
            if (this.digits.length < 9) this.digits += key
        },
        async savePad() {
            if (! this.digits) return
            await $wire.record(this.pad.id, parseInt(this.digits, 10))
            this.pad = null
        },
     }" @if ($this->polling) wire:poll.30s="checkForNew" @endif data-test="capture-inbox">
    @if ($this->pending->isNotEmpty())
        <x-ui.card class="mx-4 mb-3 px-[18px] pb-2 pt-[18px]" data-test="capture-pending">
            <div class="flex items-baseline justify-between">
                <span class="text-[15px] font-semibold">{{ __('Waiting for you') }}</span>
                <span class="text-[13px] text-muted">{{ __('captured automatically') }}</span>
            </div>
            @foreach ($this->pending as $capture)
                @php $related = $capture->relatedTransaction; @endphp
                <div @class(['py-3', 'border-b border-line' => ! $loop->last]) wire:key="pending-{{ $capture->id }}" data-test="pending-capture">
                    <div class="grid grid-cols-[36px_minmax(0,1fr)_auto] items-center gap-3">
                        <x-ui.icon-tile :icon="$reasonIcons[$capture->reason?->value] ?? 'bolt'" tone="accent" :size="36" />
                        <span class="min-w-0">
                            <span class="block truncate text-[15px]">{{ $capture->merchant ?? __('Card payment') }}</span>
                            <span class="mt-0.5 block text-xs leading-snug text-muted">{{ $capture->reason?->label() }}</span>
                        </span>
                        <span class="num text-[15px] font-medium">{{ $capture->formattedAmount() }}</span>
                    </div>

                    @if ($capture->reason === \App\Enums\CaptureReason::ForeignCurrency)
                            <div class="mt-2.5 flex gap-2 pl-12">
                                <x-ui.button size="sm" variant="light" x-on:click="pad = { id: {{ $capture->id }}, label: @js(($capture->merchant ?? __('Card payment')).' · '.$capture->formattedAmount()) }; digits = ''" data-test="enter-amount">{{ __('Enter it in :currency', ['currency' => $currency->value]) }}</x-ui.button>
                                <x-ui.button size="sm" variant="secondary" wire:click="dismiss({{ $capture->id }})">{{ __('Dismiss') }}</x-ui.button>
                            </div>
                    @elseif ($capture->reason === \App\Enums\CaptureReason::PossibleDuplicate)
                            @if ($related)
                                <div class="num mt-1.5 pl-12 text-xs text-muted">{{ __('Typed in: :category :amount', ['category' => $related->category->name, 'amount' => money($related->amount)]) }}</div>
                            @endif
                            <div class="mt-2.5 flex gap-2 pl-12">
                                <x-ui.button size="sm" variant="light" wire:click="dismiss({{ $capture->id }})" data-test="dismiss-duplicate">{{ __('Same payment, dismiss') }}</x-ui.button>
                                <x-ui.button size="sm" variant="secondary" wire:click="record({{ $capture->id }})" data-test="record-anyway">{{ __('Record it too') }}</x-ui.button>
                            </div>
                    @elseif ($capture->reason === \App\Enums\CaptureReason::Refund)
                            @if ($related && $capture->base_amount !== null)
                                @php $left = max(0, $related->amount - $capture->base_amount); @endphp
                                <div class="num mt-1.5 pl-12 text-xs text-muted">
                                    {{ $left > 0
                                        ? __(':merchant :amount becomes :left.', ['merchant' => $related->merchant, 'amount' => money($related->amount), 'left' => money($left)])
                                        : __(':merchant :amount is removed.', ['merchant' => $related->merchant, 'amount' => money($related->amount)]) }}
                                </div>
                                <div class="mt-2.5 flex gap-2 pl-12">
                                    <x-ui.button size="sm" variant="light" wire:click="applyRefund({{ $capture->id }})" data-test="apply-refund">{{ __('Take it off') }}</x-ui.button>
                                    <x-ui.button size="sm" variant="secondary" wire:click="dismiss({{ $capture->id }})">{{ __('Dismiss') }}</x-ui.button>
                                </div>
                            @else
                                <div class="mt-1.5 pl-12 text-xs text-muted">{{ __('No matching spending found this month. Correct it by hand if needed.') }}</div>
                                <div class="mt-2.5 flex gap-2 pl-12">
                                    <x-ui.button size="sm" variant="secondary" wire:click="dismiss({{ $capture->id }})">{{ __('Dismiss') }}</x-ui.button>
                                </div>
                            @endif
                    @elseif ($capture->reason === \App\Enums\CaptureReason::PeriodClosed)
                            <div class="mt-2.5 flex gap-2 pl-12">
                                <x-ui.button size="sm" variant="light" wire:click="record({{ $capture->id }})" data-test="record-today">{{ __('Record it for today') }}</x-ui.button>
                                <x-ui.button size="sm" variant="secondary" wire:click="dismiss({{ $capture->id }})">{{ __('Dismiss') }}</x-ui.button>
                            </div>
                    @else
                            <div class="mt-2.5 flex gap-2 pl-12">
                                <x-ui.button size="sm" variant="secondary" wire:click="dismiss({{ $capture->id }})">{{ __('Dismiss') }}</x-ui.button>
                            </div>
                    @endif
                </div>
            @endforeach
        </x-ui.card>
    @endif

    @if ($this->recent->isNotEmpty())
        <x-ui.card class="mx-4 mb-3 px-[18px] pb-1.5 pt-[18px]" data-test="capture-recent">
            <div class="flex items-baseline justify-between">
                <span class="text-[15px] font-semibold">{{ __('Captured today') }}</span>
                <span class="text-[13px] text-muted">{{ __('tap to change') }}</span>
            </div>
            @foreach ($this->recent as $transaction)
                <button type="button" wire:key="recent-{{ $transaction->id }}"
                        x-on:click="selected = @js(['id' => $transaction->id, 'title' => $transaction->merchant ?? $transaction->category->name, 'amount' => money($transaction->amount), 'categoryId' => $transaction->category_id])"
                        @class(['grid w-full grid-cols-[36px_minmax(0,1fr)_auto] items-center gap-3 py-[11px] text-left', 'border-b border-line' => ! $loop->last]) data-test="recent-capture">
                    <x-ui.icon-tile :icon="Icons::forCategory($transaction->category->icon)" :size="36" />
                    <span class="min-w-0">
                        <span class="block truncate text-[15px]">{{ $transaction->merchant ?? $transaction->category->name }}</span>
                        <span class="mt-0.5 flex items-center gap-1 text-xs text-muted"><x-ui.icon name="bolt" :size="14" class="text-accent" />{{ $transaction->category->name }}</span>
                    </span>
                    <span class="num text-[15px] font-medium">{{ money_number($transaction->amount) }}</span>
                </button>
            @endforeach
        </x-ui.card>
    @endif

    <x-capture-sheets :categories="$this->categories" />
</div>
