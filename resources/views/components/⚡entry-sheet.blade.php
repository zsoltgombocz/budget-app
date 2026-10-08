<?php

use App\Actions\Budget\MarkNoSpendDay;
use App\Actions\Budget\RecordTransaction;
use App\Enums\LineType;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CaptureDeduplicator;
use App\Services\OverviewService;
use App\Services\PeriodService;
use App\Support\Icons;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    /** @var list<array{id: int, name: string, icon: string, planned: int, remaining: int}> */
    public array $options = [];

    public bool $noSpendMarked = false;

    public function mount(): void
    {
        $this->options = $this->loadOptions();
        $this->noSpendMarked = app(MarkNoSpendDay::class)->isMarked($this->user());
    }

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

        $user = $this->user();
        $currency = $user->settings()->currency;

        $transaction = app(RecordTransaction::class)->handle(
            $user,
            $validated['category'],
            $validated['amount'] * $currency->minorPerMajor(),
            CarbonImmutable::parse($validated['date']),
            $validated['note'],
            $validated['client_uuid'],
        );

        $this->options = $this->loadOptions();
        $this->noSpendMarked = app(MarkNoSpendDay::class)->isMarked($user);
        $category = collect($this->options)->firstWhere('id', $transaction->category_id);
        $alreadyCaptured = app(CaptureDeduplicator::class)->findAutoTwin($transaction) !== null;

        $this->dispatch('budget-updated');
        $this->dispatch('app-toast',
            title: __('Saved').' · '.($category['name'] ?? '').' '.Money::of($transaction->amount, $currency)->format(),
            subtitle: $alreadyCaptured ? __('Already captured automatically today?') : ($category !== null && $category['planned'] > 0
                ? ($category['remaining'] >= 0
                    ? __(':amount left in the budget', ['amount' => Money::of($category['remaining'], $currency)->format()])
                    : __(':amount over the budget', ['amount' => Money::of(-$category['remaining'], $currency)->format()]))
                : null),
            undo: 'undo-transaction',
            params: ['id' => $transaction->id],
        );
    }

    #[On('undo-transaction')]
    public function undoTransaction(int $id): void
    {
        Transaction::query()->whereKey($id)->where('user_id', $this->user()->id)->first()?->delete();
        $this->options = $this->loadOptions();

        $this->dispatch('budget-updated');
        $this->dispatch('app-toast', title: __('Entry removed.'), icon: 'undo');
    }

    public function toggleNoSpend(): void
    {
        $action = app(MarkNoSpendDay::class);

        if ($action->isMarked($this->user())) {
            $this->undoNoSpend();

            return;
        }

        $action->handle($this->user());
        $this->noSpendMarked = true;

        $this->dispatch('budget-updated');
        $this->dispatch('app-toast',
            title: __("I didn't spend today"),
            subtitle: __('Noted, no reminder today.'),
            icon: 'do_not_disturb_on',
            undo: 'undo-no-spend',
        );
    }

    #[On('undo-no-spend')]
    public function undoNoSpend(): void
    {
        app(MarkNoSpendDay::class)->unmark($this->user());
        $this->noSpendMarked = false;

        $this->dispatch('budget-updated');
        $this->dispatch('app-toast', title: __('No-spend mark removed.'), icon: 'undo');
    }

    #[On('budget-updated')]
    public function refresh(): void
    {
        $this->options = $this->loadOptions();
        $this->noSpendMarked = app(MarkNoSpendDay::class)->isMarked($this->user());
    }

    /**
     * Quick entry categories with what is left of their budget this period.
     *
     * @return list<array{id: int, name: string, icon: string, planned: int, remaining: int}>
     */
    private function loadOptions(): array
    {
        $user = $this->user();
        $forecast = collect(app(OverviewService::class)->forUser($user)->forecast->categories)->keyBy('categoryId');

        return $user->categories()
            ->where('type', LineType::Variable)
            ->where('is_quick_entry', true)
            ->orderBy('sort')
            ->orderBy('name')
            ->get()
            ->map(fn ($category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'icon' => Icons::forCategory($category->icon),
                'planned' => $forecast->get($category->id)?->planned ?? 0,
                'remaining' => $forecast->get($category->id)?->remaining() ?? 0,
            ])
            ->values()
            ->all();
    }

    #[Computed]
    public function today(): string
    {
        return app(PeriodService::class)->today($this->user()->settings())->toDateString();
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php $currency = user_currency(); @endphp
<div x-data="entrySheet({
        today: @js($this->today),
        locale: @js(str_replace('_', '-', app()->getLocale())),
        minor: {{ $currency->minorPerMajor() }},
        texts: {
            empty: @js(__('Type the amount')),
            left: @js(__(':category budget left: :amount')),
            over: @js(__(':amount over the :category budget')),
            today: @js(__('Today')),
        },
    })"
     x-on:open-entry.window="show()"
     x-on:keydown.window="onKey($event)"
     x-effect="document.documentElement.classList.toggle('overflow-hidden', open)"
     data-test="entry-sheet">
    <div x-show="open" x-cloak class="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-label="{{ __('New spending') }}">
        <div x-show="open" x-transition.opacity class="absolute inset-0 bg-black/55" x-on:click="close()"></div>

        <div x-show="open"
             x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
             x-transition:leave="transition duration-200 ease-in" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
             class="absolute inset-x-0 bottom-0 top-[max(5rem,calc(var(--safe-top)+4rem))] mx-auto flex max-w-lg flex-col rounded-t-[30px] bg-surface px-4 pb-[calc(1.25rem+env(safe-area-inset-bottom))] pt-2">
            <div class="mx-auto h-[5px] w-9 shrink-0 rounded-full bg-ink/18"></div>
            <div class="mt-1 grid h-11 shrink-0 grid-cols-[72px_1fr_72px] items-center">
                <button type="button" class="text-left text-[15px] text-muted" x-on:click="close()">{{ __('Cancel') }}</button>
                <div class="text-center text-base font-semibold">{{ __('New spending') }}</div>
                <span></span>
            </div>

            @if ($options === [])
                <div class="flex flex-1 flex-col justify-center">
                    <x-ui.empty-state icon="category" :title="__('No quick entry categories yet')">
                        {{ __('Add a variable budget line on the Plan screen and it shows up here.') }}
                        <x-slot name="actions">
                            <x-ui.button size="md" variant="secondary" :href="route('plan')" wire:navigate>{{ __('Open the plan') }}</x-ui.button>
                        </x-slot>
                    </x-ui.empty-state>
                </div>
            @else
                <div class="shrink-0 pb-1 pt-2.5 text-center">
                    <div class="num flex items-baseline justify-center gap-2">
                        <span class="text-[60px] font-semibold leading-[1.05] tracking-[-0.045em]" :class="digits ? 'text-ink' : 'text-faint'" x-text="formatted()" aria-live="polite"></span>
                        <span class="text-[26px] font-medium text-muted">{{ $currency->symbol() }}</span>
                    </div>
                    <div class="num mt-1.5 text-[13px]" :class="hintClass()" x-text="hint()"></div>
                </div>

                <div class="no-scrollbar -mx-4 mt-3.5 flex shrink-0 gap-2 overflow-x-auto px-4" data-test="category-grid">
                    <template x-for="category in categories" :key="category.id">
                        <button type="button" x-on:click="pick(category.id)"
                                class="flex h-[70px] min-w-[calc((100%-32px)/5)] flex-1 flex-col items-center justify-center gap-1.5 rounded-btn border-[1.5px] px-1 transition active:scale-95"
                                :class="category.id === categoryId ? 'border-accent bg-accent/14 text-accent' : 'border-transparent bg-surface-2 text-ink-2'">
                            <span class="ms" style="font-size:24px;width:24px;height:24px" x-text="category.icon" aria-hidden="true"></span>
                            <span class="max-w-full truncate text-xs font-medium" x-text="category.name"></span>
                        </button>
                    </template>
                </div>

                <div class="mt-3 flex shrink-0 gap-2">
                    <label class="relative flex h-11 flex-none items-center gap-1.5 rounded-[14px] bg-surface-2 px-3.5 text-sm font-medium">
                        <x-ui.icon name="calendar_today" :size="18" class="text-muted" />
                        <span x-text="date === today ? texts.today : shortDate(date)"></span>
                        <input type="date" x-model="date" :max="today" class="absolute inset-0 opacity-0" aria-label="{{ __('Date') }}">
                    </label>
                    <label class="flex h-11 min-w-0 flex-1 items-center gap-1.5 rounded-[14px] bg-surface-2 px-3.5 text-sm text-zinc-500 focus-within:ring-2 focus-within:ring-accent">
                        <x-ui.icon name="edit_note" :size="18" />
                        <input type="text" x-model="note" maxlength="255" placeholder="{{ __('Note (optional)') }}" class="min-w-0 flex-1 bg-transparent text-ink outline-none placeholder:text-zinc-500">
                    </label>
                </div>

                <div class="mt-3 grid min-h-0 flex-1 grid-cols-3 gap-1.5">
                    <template x-for="key in ['1','2','3','4','5','6','7','8','9','000','0','del']" :key="key">
                        <button type="button" x-on:click="press(key)" :data-key="key"
                                class="num flex min-h-[50px] items-center justify-center rounded-2xl bg-ink/4 text-[26px] font-medium transition active:bg-ink/12"
                                :aria-label="key === 'del' ? @js(__('Delete')) : key">
                            <span x-show="key !== 'del'" x-text="key"></span>
                            <span x-show="key === 'del'" class="ms" style="font-size:24px;width:24px;height:24px" aria-hidden="true">backspace</span>
                        </button>
                    </template>
                </div>

                <div class="mt-3 flex shrink-0 items-center gap-3">
                    <button type="button" x-on:click="noSpend()" class="flex h-14 items-center gap-1 px-1.5 text-sm font-medium underline decoration-ink/25 underline-offset-[3px]"
                            :class="$wire.noSpendMarked ? 'text-accent' : 'text-ink-2'" data-test="no-spend">
                        <span x-show="$wire.noSpendMarked" class="ms" style="font-size:18px;width:18px;height:18px" aria-hidden="true">check</span>
                        <span x-text="$wire.noSpendMarked ? @js(__('Marked · undo')) : @js(__("I didn't spend today"))"></span>
                    </button>
                    <button type="button" x-on:click="submit()" :disabled="! digits || saving"
                            class="h-14 flex-1 rounded-btn text-[17px] font-semibold transition active:scale-[0.98]"
                            :class="[digits ? 'bg-accent text-accent-ink' : 'bg-surface-3 text-zinc-500']" data-test="save-entry">{{ __('Save') }}</button>
                </div>
            @endif
        </div>
    </div>
</div>

@script
<script>
    Alpine.data('entrySheet', ({ today, locale, minor, texts }) => ({
        open: false,
        get categories() { return $wire.options },
        categoryId: null,
        digits: '',
        date: today,
        today,
        note: '',
        saving: false,
        texts,
        numberFormat: new Intl.NumberFormat(locale, { maximumFractionDigits: 0, useGrouping: 'always' }),
        moneyFormat: null,

        init() {
            this.$watch('$wire.options', () => this.ensureCategory())
            this.ensureCategory()
            const params = new URLSearchParams(location.search)
            if (params.has('rogzites')) {
                params.delete('rogzites')
                history.replaceState(null, '', location.pathname + (params.size ? '?' + params : ''))
                this.show()
            }
        },

        ensureCategory() {
            if (this.categories.some(c => c.id === this.categoryId)) return
            let remembered = null
            try { remembered = parseInt(localStorage.getItem('entry-category'), 10) } catch (e) {}
            this.categoryId = (this.categories.find(c => c.id === remembered) ?? this.categories[0])?.id ?? null
        },

        show() {
            this.digits = ''
            this.note = ''
            this.date = this.today
            this.open = true
        },

        close() {
            this.open = false
        },

        pick(id) {
            this.categoryId = id
            try { localStorage.setItem('entry-category', id) } catch (e) {}
        },

        press(key) {
            if (key === 'del') { this.digits = this.digits.slice(0, -1); return }
            if (key === '000') { if (this.digits) this.digits = (this.digits + '000').slice(0, 9); return }
            if (this.digits === '' && key === '0') return
            if (this.digits.length < 9) this.digits += key
        },

        onKey(event) {
            if (! this.open || event.target.closest('input, textarea')) return
            if (/^[0-9]$/.test(event.key)) this.press(event.key)
            else if (event.key === 'Backspace') this.press('del')
            else if (event.key === 'Enter') this.submit()
            else if (event.key === 'Escape') this.close()
        },

        amount() {
            return parseInt(this.digits || '0', 10)
        },

        formatted() {
            return this.numberFormat.format(this.amount())
        },

        category() {
            return this.categories.find(c => c.id === this.categoryId)
        },

        left() {
            const c = this.category()
            return c ? c.remaining - this.amount() * minor : 0
        },

        money(minorAmount) {
            return this.numberFormat.format(Math.round(minorAmount / minor))
        },

        hint() {
            const c = this.category()
            if (! this.digits || ! c) return this.texts.empty
            if (c.planned <= 0) return c.name
            const left = this.left()
            return left >= 0
                ? this.texts.left.replace(':category', c.name).replace(':amount', this.money(left) + ' ' + @js(user_currency()->symbol()))
                : this.texts.over.replace(':category', c.name).replace(':amount', this.money(-left) + ' ' + @js(user_currency()->symbol()))
        },

        hintClass() {
            const c = this.category()
            if (! this.digits || ! c || c.planned <= 0) return 'text-muted'
            const left = this.left()
            if (left < 0) return 'text-danger'
            return left / c.planned < 0.2 ? 'text-warn' : 'text-muted'
        },

        shortDate(value) {
            return new Intl.DateTimeFormat(locale, { month: 'short', day: 'numeric' }).format(new Date(value + 'T12:00:00'))
        },

        uuid() {
            if (window.crypto?.randomUUID) return window.crypto.randomUUID()
            return '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, c =>
                (+c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> +c / 4).toString(16))
        },

        async submit() {
            if (! this.digits || this.saving || ! this.categoryId) return
            this.saving = true
            try {
                await $wire.save(this.categoryId, this.amount(), this.date, this.note || null, this.uuid())
                navigator.vibrate?.(30)
                this.close()
            } finally {
                this.saving = false
            }
        },

        async noSpend() {
            const wasMarked = $wire.noSpendMarked
            await $wire.toggleNoSpend()
            if (! wasMarked) this.close()
        },
    }))
</script>
@endscript
