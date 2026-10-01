<?php

use App\Actions\Budget\MarkNoSpendDay;
use App\Actions\Budget\RecordTransaction;
use App\Enums\Currency;
use App\Enums\LineType;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Record spending')] #[Layout('layouts::app', ['tabs' => false])] class extends Component {
    public ?int $lastTransactionId = null;

    public function save(int $categoryId, int $amount, string $date, ?string $note, string $clientUuid): void
    {
        $validated = validator(
            ['category' => $categoryId, 'amount' => $amount, 'date' => $date, 'note' => $note, 'client_uuid' => $clientUuid],
            [
                'category' => ['required', 'integer'],
                'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
                'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->today],
                'note' => ['nullable', 'string', 'max:255'],
                'client_uuid' => ['required', 'uuid'],
            ],
        )->validate();

        $transaction = app(RecordTransaction::class)->handle(
            $this->user(),
            $validated['category'],
            $validated['amount'] * $this->currency->minorPerMajor(),
            CarbonImmutable::parse($validated['date']),
            $validated['note'],
            $validated['client_uuid'],
        );

        $this->lastTransactionId = $transaction->id;
        unset($this->todayTotal);
    }

    public function undo(): void
    {
        if ($this->lastTransactionId === null) {
            return;
        }

        Transaction::query()->whereKey($this->lastTransactionId)->delete();
        $this->lastTransactionId = null;
        unset($this->todayTotal);

        Flux::toast(text: __('Entry removed.'));
    }

    public function markNoSpend(): void
    {
        app(MarkNoSpendDay::class)->handle($this->user());

        Flux::toast(variant: 'success', text: __('Noted, no reminder today.'));

        $this->redirectRoute('dashboard', navigate: true);
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return $this->user()->categories()
            ->where('type', LineType::Variable)
            ->where('is_quick_entry', true)
            ->orderBy('sort')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function currency(): Currency
    {
        return $this->user()->settings()->currency;
    }

    #[Computed]
    public function today(): string
    {
        return app(PeriodService::class)->today($this->user()->settings())->toDateString();
    }

    #[Computed]
    public function todayTotal(): int
    {
        return (int) $this->user()->transactions()->whereDate('occurred_on', $this->today)->sum('amount');
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

<div
    x-data="entrySheet({
        locale: @js(str_replace('_', '-', app()->getLocale())),
        currency: @js($this->currency->value),
        today: @js($this->today),
    })"
    x-on:keydown.window="onKey($event)"
    class="flex flex-col gap-4"
>
    {{-- Step 1: category grid --}}
    <section x-show="! category" class="flex flex-col gap-4">
        <div class="flex items-baseline justify-between">
            <flux:heading size="lg">{{ __('What did you spend on?') }}</flux:heading>
            <flux:text class="text-sm">{{ __('Today') }}: <x-money :amount="$this->todayTotal" /></flux:text>
        </div>

        @if ($this->categories->isEmpty())
            <flux:callout icon="information-circle">
                <flux:callout.heading>{{ __('No quick entry categories yet') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Add a variable budget line on the Plan screen and it shows up here.') }}</flux:callout.text>
                <x-slot name="actions">
                    <flux:button size="sm" :href="route('plan')" wire:navigate>{{ __('Open the plan') }}</flux:button>
                </x-slot>
            </flux:callout>
        @else
            <div class="grid grid-cols-3 gap-3" data-test="category-grid">
                @foreach ($this->categories as $category)
                    <button type="button" wire:key="category-{{ $category->id }}"
                            x-on:click="pick({ id: {{ $category->id }}, name: @js($category->name) })"
                            class="flex aspect-square flex-col items-center justify-center gap-2 rounded-2xl border border-zinc-200 bg-white p-2 text-center text-sm font-medium shadow-xs transition active:scale-95 dark:border-zinc-700 dark:bg-zinc-800">
                        <x-category-icon :category="$category" class="size-7" />
                        <span class="line-clamp-2 leading-tight">{{ $category->name }}</span>
                    </button>
                @endforeach
            </div>
        @endif

        <flux:button wire:click="markNoSpend" icon="hand-thumb-up" class="w-full" data-test="no-spend">
            {{ __("I didn't spend today") }}
        </flux:button>

        <flux:button :href="route('dashboard')" variant="ghost" wire:navigate class="w-full">{{ __('Back') }}</flux:button>
    </section>

    {{-- Step 2: amount on the numpad --}}
    <section x-show="category" x-cloak class="flex flex-col gap-4">
        <div class="flex items-center gap-2">
            <flux:button variant="ghost" size="sm" icon="arrow-left" x-on:click="category = null" aria-label="{{ __('Back') }}" />
            <flux:heading size="lg" x-text="category?.name"></flux:heading>
        </div>

        <output class="block rounded-2xl bg-white py-6 text-center text-4xl font-semibold tabular-nums shadow-xs dark:bg-zinc-800"
                x-text="formatted()" aria-live="polite"></output>

        <div class="grid grid-cols-3 gap-2">
            <template x-for="key in ['1','2','3','4','5','6','7','8','9','000','0','back']" :key="key">
                <button type="button" x-on:click="press(key)"
                        class="h-14 rounded-xl bg-white text-2xl font-medium shadow-xs transition active:scale-95 active:bg-zinc-100 dark:bg-zinc-800 dark:active:bg-zinc-700"
                        :aria-label="key === 'back' ? @js(__('Delete')) : key">
                    <span x-show="key !== 'back'" x-text="key"></span>
                    <flux:icon.backspace x-show="key === 'back'" class="mx-auto size-6" />
                </button>
            </template>
        </div>

        <div x-show="showDetails" class="grid gap-3">
            <flux:input type="date" x-model="date" :label="__('Date')" ::max="today" />
            <flux:input x-model="note" :label="__('Note')" :placeholder="__('Optional')" maxlength="255" />
        </div>

        <div class="flex gap-2">
            <flux:button variant="ghost" icon="calendar" x-on:click="showDetails = ! showDetails">
                <span x-text="date === today ? @js(__('Today')) : date"></span>
            </flux:button>
            <flux:button variant="primary" class="flex-1" x-on:click="submit()" ::disabled="! digits || saving" data-test="save-entry">
                {{ __('Save') }}
            </flux:button>
        </div>
    </section>

    {{-- Undo bar for the last entry --}}
    <div x-show="undoVisible" x-cloak x-transition
         class="fixed inset-x-4 bottom-[calc(1rem+env(safe-area-inset-bottom))] z-40 mx-auto flex max-w-md items-center gap-3 rounded-xl bg-zinc-900 px-4 py-3 text-sm text-white shadow-lg dark:bg-white dark:text-zinc-900">
        <flux:icon.check-circle class="size-5 text-emerald-400 dark:text-emerald-600" />
        <span class="flex-1" x-text="lastSaved"></span>
        <button type="button" class="font-semibold underline" x-on:click="undo()">{{ __('Undo') }}</button>
    </div>
</div>

@script
<script>
    Alpine.data('entrySheet', ({ locale, currency, today }) => ({
        category: null,
        digits: '',
        date: today,
        today,
        note: '',
        showDetails: false,
        saving: false,
        undoVisible: false,
        lastSaved: '',
        undoTimer: null,
        formatter: new Intl.NumberFormat(locale, { style: 'currency', currency, maximumFractionDigits: 0 }),

        pick(category) {
            this.category = category
            this.digits = ''
        },

        press(key) {
            if (key === 'back') {
                this.digits = this.digits.slice(0, -1)
                return
            }
            if (this.digits === '' && key.startsWith('0')) return
            if ((this.digits + key).length > 9) return
            this.digits += key
        },

        onKey(event) {
            if (! this.category || event.target.closest('input, textarea')) return
            if (/^[0-9]$/.test(event.key)) this.press(event.key)
            if (event.key === 'Backspace') this.press('back')
            if (event.key === 'Enter') this.submit()
            if (event.key === 'Escape') this.category = null
        },

        formatted() {
            return this.formatter.format(parseInt(this.digits || '0', 10))
        },

        uuid() {
            if (window.crypto?.randomUUID) return window.crypto.randomUUID()
            return '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, c =>
                (+c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> +c / 4).toString(16))
        },

        async submit() {
            if (! this.digits || this.saving) return
            this.saving = true
            const label = `${this.category.name}: ${this.formatted()}`
            try {
                await $wire.save(this.category.id, parseInt(this.digits, 10), this.date, this.note || null, this.uuid())
                navigator.vibrate?.(30)
                this.lastSaved = label
                this.undoVisible = true
                clearTimeout(this.undoTimer)
                this.undoTimer = setTimeout(() => this.undoVisible = false, 6000)
                this.category = null
                this.digits = ''
                this.note = ''
                this.date = this.today
                this.showDetails = false
            } finally {
                this.saving = false
            }
        },

        async undo() {
            this.undoVisible = false
            await $wire.undo()
        },
    }))
</script>
@endscript
