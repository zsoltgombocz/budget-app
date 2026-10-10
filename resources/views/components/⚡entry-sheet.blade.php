<?php

use App\Actions\Budget\MarkNoSpendDay;
use App\Actions\Budget\RecordTransaction;
use App\Enums\LineType;
use App\Models\Transaction;
use App\Models\User;
use App\Services\OverviewService;
use App\Services\PeriodService;
use App\Support\Icons;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    /** Largest amount accepted in one entry, in major units. */
    private const int MAX_MAJOR = 1_000_000_000;

    /** @var list<array{id: int, name: string, icon: string, planned: int, remaining: int}> */
    public array $options = [];

    public bool $noSpendMarked = false;

    /** Whether a spending is already recorded for today: then "I didn't spend today" makes no sense. */
    public bool $spentToday = false;

    /** Today in the user's timezone; the sheet survives navigation, so it is reloaded when the day changes. */
    public string $today = '';

    public function mount(): void
    {
        $this->loadState();
    }

    /**
     * Record a spending from the whole form in one call. The amount is the typed
     * string (e.g. "12,34"), parsed exactly into minor units.
     *
     * @param  array<string, mixed>  $form
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function save(array $form): array
    {
        $user = $this->user();
        $currency = $user->settings()->currency;
        $amount = is_string($form['amount'] ?? null) ? Money::parse($form['amount'], $currency) : null;

        $validator = Validator::make([
            'category' => $form['category'] ?? null,
            'date' => $form['date'] ?? null,
            'note' => $form['note'] ?? null,
            'client_uuid' => $form['clientUuid'] ?? null,
        ], [
            'category' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->currentDay()],
            'note' => ['nullable', 'string', 'max:255'],
            'client_uuid' => ['required', 'uuid'],
        ]);

        $errors = $validator->fails() ? $this->firstMessages($validator->errors()->toArray()) : [];

        if ($amount === null || $amount > self::MAX_MAJOR * $currency->minorPerMajor()) {
            $errors['amount'] = __('Enter a valid amount.');
        } elseif ($amount < 1) {
            $errors['amount'] = __('The amount must be greater than zero.');
        }

        if ($errors !== [] || $amount === null) {
            return ['ok' => false, 'errors' => $errors];
        }

        /** @var array{category: int|string, date: string, note: ?string, client_uuid: string} $validated */
        $validated = $validator->validated();

        try {
            $transaction = app(RecordTransaction::class)->handle(
                $user,
                (int) $validated['category'],
                $amount,
                CarbonImmutable::parse($validated['date']),
                $validated['note'],
                $validated['client_uuid'],
            );
        } catch (ValidationException $exception) {
            return ['ok' => false, 'errors' => $this->firstMessages($exception->errors())];
        }

        $this->loadState();
        $category = collect($this->options)->firstWhere('id', $transaction->category_id);

        $this->dispatch('budget-updated');
        $this->dispatch('app-toast',
            title: __('Saved').' · '.($category['name'] ?? '').' '.Money::of($transaction->amount, $currency)->format(),
            subtitle: $category !== null && $category['planned'] > 0
                ? ($category['remaining'] >= 0
                    ? __(':amount left in the budget', ['amount' => Money::of($category['remaining'], $currency)->format()])
                    : __(':amount over the budget', ['amount' => Money::of(-$category['remaining'], $currency)->format()]))
                : null,
            undo: 'undo-transaction',
            params: ['id' => $transaction->id],
        );

        return ['ok' => true, 'errors' => []];
    }

    #[On('undo-transaction')]
    public function undoTransaction(int $id): void
    {
        Transaction::query()->whereKey($id)->where('user_id', $this->user()->id)->whereRelation('period', 'status', 'open')->first()?->delete();
        $this->loadState();

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
        $this->loadState();
    }

    /**
     * Today, the categories and today's marks, reloaded together so they never disagree.
     */
    private function loadState(): void
    {
        $user = $this->user();
        $this->today = $this->currentDay();
        $this->options = $this->loadOptions();
        $this->noSpendMarked = app(MarkNoSpendDay::class)->isMarked($user);
        $this->spentToday = $user->transactions()->whereDate('occurred_on', $this->today)->exists();
    }

    /**
     * The first message of each field, as the sheets show one line per field.
     *
     * @param  array<string, array<int, string>>  $messages
     * @return array<string, string>
     */
    private function firstMessages(array $messages): array
    {
        return array_map(fn (array $fieldMessages): string => $fieldMessages[0] ?? '', $messages);
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

    private function currentDay(): string
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

@php
    $currency = user_currency();
    $timezone = auth()->user()?->settings()->timezone ?? config('app.timezone');
    $keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9', $currency->decimals() > 0 ? ',' : '000', '0', 'del'];
@endphp
<div x-data="entrySheet({
        today: @js($today),
        timezone: @js($timezone),
        locale: @js(str_replace('_', '-', app()->getLocale())),
        minor: {{ $currency->minorPerMajor() }},
        decimals: {{ $currency->decimals() }},
        symbol: @js($currency->symbol()),
        texts: {
            empty: @js(__('Type the amount')),
            left: @js(__(':category budget left: :amount')),
            over: @js(__(':amount over the :category budget')),
            today: @js(__('Today')),
            failed: @js(__('Could not save. Check your connection and try again.')),
        },
    })"
     x-on:open-entry.window="show()"
     x-on:keydown.window="onKey($event)"
     x-effect="document.documentElement.classList.toggle('overflow-hidden', open)"
     data-test="entry-sheet">
    <div x-show="open" x-cloak class="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-label="{{ __('New spending') }}">
        <div x-show="open" x-transition.opacity class="absolute inset-0 bg-black/55" x-on:click="close()"></div>

        {{--
            Sizes scale with the viewport height (clamp + dvh) so the whole sheet fits from 568px (iPhone SE,
            Safari with toolbar) up: the gap above the sheet, the amount, the chips, the keys and the Save row
            shrink on short screens. The middle scrolls as a last resort; the Save row never moves off screen.
        --}}
        <div x-show="open"
             x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
             x-transition:leave="transition duration-200 ease-in" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
             class="absolute inset-x-0 bottom-0 top-[max(clamp(1rem,calc(100dvh-37rem),5rem),calc(var(--safe-top)+clamp(0.5rem,calc(100dvh-37rem),4rem)))] mx-auto flex max-w-lg flex-col rounded-t-[30px] bg-surface px-4 pb-[calc(clamp(0.5rem,1.5dvh,1.25rem)+env(safe-area-inset-bottom))] pt-2"
             :aria-busy="busy">
            <div class="mx-auto h-[5px] w-9 shrink-0 rounded-full bg-ink/18"></div>
            <div class="mt-1 grid h-[clamp(36px,5.2dvh,44px)] shrink-0 grid-cols-[72px_1fr_72px] items-center">
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
                <div class="no-scrollbar -mx-4 flex min-h-0 flex-1 flex-col overflow-y-auto overscroll-contain px-4">
                    <div class="shrink-0 pb-1 pt-[clamp(0px,1dvh,10px)] text-center">
                        <div class="num flex items-baseline justify-center gap-2">
                            <span class="text-[clamp(40px,7dvh,60px)] font-semibold leading-[1.05] tracking-[-0.045em]" :class="digits ? 'text-ink' : 'text-faint'" x-text="formatted()" aria-live="polite"></span>
                            <span class="text-[clamp(20px,3dvh,26px)] font-medium text-muted">{{ $currency->symbol() }}</span>
                        </div>
                        <div class="num mt-1 text-[13px]" :class="error ? 'font-medium text-danger' : hintClass()" x-text="error ?? hint()" aria-live="polite" data-test="entry-hint"></div>
                    </div>

                    <div class="no-scrollbar -mx-4 mt-[clamp(8px,1.6dvh,14px)] flex shrink-0 gap-2 overflow-x-auto px-4" data-test="category-grid">
                        <template x-for="category in categories" :key="category.id">
                            <button type="button" x-on:click="pick(category.id)" :disabled="busy"
                                    class="flex h-[clamp(52px,8.2dvh,70px)] min-w-[calc((100%-32px)/5)] flex-1 flex-col items-center justify-center gap-[clamp(2px,0.7dvh,6px)] rounded-btn border-[1.5px] px-1 transition active:scale-95"
                                    :class="category.id === categoryId ? 'border-accent bg-accent/14 text-accent' : 'border-transparent bg-surface-2 text-ink-2'">
                                <span class="ms" style="font-size:24px;width:24px;height:24px" x-text="category.icon" aria-hidden="true"></span>
                                <span class="max-w-full truncate text-xs font-medium" x-text="category.name"></span>
                            </button>
                        </template>
                    </div>

                    <div class="mt-[clamp(6px,1.4dvh,12px)] flex shrink-0 gap-2">
                        <label class="relative flex h-[clamp(38px,5.2dvh,44px)] flex-none items-center gap-1.5 rounded-[14px] bg-surface-2 px-3.5 text-sm font-medium">
                            <x-ui.icon name="calendar_today" :size="18" class="text-muted" />
                            <span x-text="date === today ? texts.today : shortDate(date)"></span>
                            <input type="date" x-model="date" :max="today" x-on:change="error = null" class="absolute inset-0 opacity-0" aria-label="{{ __('Date') }}">
                        </label>
                        <label class="flex h-[clamp(38px,5.2dvh,44px)] min-w-0 flex-1 items-center gap-1.5 rounded-[14px] bg-surface-2 px-3.5 text-sm text-zinc-500 focus-within:ring-2 focus-within:ring-accent">
                            <x-ui.icon name="edit_note" :size="18" />
                            <input type="text" x-model="note" maxlength="255" placeholder="{{ __('Note (optional)') }}" class="min-w-0 flex-1 bg-transparent text-ink outline-none placeholder:text-zinc-500">
                        </label>
                    </div>

                    <div class="mt-[clamp(6px,1.4dvh,12px)] grid flex-1 grid-cols-3 gap-1.5">
                        @foreach ($keys as $key)
                            <button type="button" x-on:click="press(@js($key))" :disabled="busy" data-key="{{ $key }}"
                                    class="num flex min-h-[clamp(40px,6.5dvh,50px)] items-center justify-center rounded-2xl bg-ink/4 text-[26px] font-medium transition active:bg-ink/12"
                                    aria-label="{{ $key === 'del' ? __('Delete') : $key }}">
                                @if ($key === 'del')
                                    <x-ui.icon name="backspace" :size="24" />
                                @else
                                    {{ $key }}
                                @endif
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="mt-[clamp(6px,1.4dvh,12px)] flex shrink-0 items-center gap-3">
                    @unless ($spentToday)
                        <button type="button" x-on:click="noSpend()" :disabled="busy"
                                class="flex h-[clamp(46px,7dvh,56px)] items-center gap-1 px-1.5 text-sm font-medium underline decoration-ink/25 underline-offset-[3px]"
                                :class="$wire.noSpendMarked ? 'text-accent' : 'text-ink-2'" data-test="no-spend">
                            <span x-show="$wire.noSpendMarked" class="ms" style="font-size:18px;width:18px;height:18px" aria-hidden="true">check</span>
                            <span x-text="$wire.noSpendMarked ? @js(__('Marked · undo')) : @js(__("I didn't spend today"))"></span>
                        </button>
                    @endunless
                    <button type="button" x-on:click="submit()" :disabled="! hasAmount() || busy"
                            class="h-[clamp(46px,7dvh,56px)] flex-1 rounded-btn text-[17px] font-semibold transition active:scale-[0.98]"
                            :class="[hasAmount() ? 'bg-accent text-accent-ink' : 'bg-surface-3 text-zinc-500', busy ? 'opacity-60' : '']" data-test="save-entry">{{ __('Save') }}</button>
                </div>
            @endif
        </div>
    </div>
</div>

@script
<script>
    Alpine.data('entrySheet', ({ today, timezone, locale, minor, decimals, symbol, texts }) => ({
        open: false,
        get categories() { return $wire.options },
        categoryId: null,
        digits: '',
        date: today,
        today,
        note: '',
        busy: false,
        error: null,
        pending: null,
        texts,
        numberFormat: new Intl.NumberFormat(locale, { maximumFractionDigits: 0, useGrouping: 'always' }),
        moneyFormat: new Intl.NumberFormat(locale, { minimumFractionDigits: decimals, maximumFractionDigits: decimals, useGrouping: 'always' }),

        init() {
            this.$watch('$wire.options', () => this.ensureCategory())
            // The server's today wins (it moves after midnight on a refresh); keep a "today" date on today.
            this.$watch('$wire.today', value => {
                if (! value || value === this.today) return
                if (this.date === this.today) this.date = value
                this.today = value
            })
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

        /** Today in the user's timezone by the device clock, to notice a day change without a request. */
        localToday() {
            try {
                return new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
            } catch (e) {
                return this.today
            }
        },

        show() {
            this.digits = ''
            this.note = ''
            this.error = null
            this.pending = null
            // The sheet lives across navigation: after midnight reload today and today's state from the server.
            const localToday = this.localToday()
            if (localToday !== this.today) {
                this.today = localToday
                $wire.refresh().catch(() => {})
            }
            this.date = this.today
            this.open = true
        },

        close() {
            this.open = false
        },

        pick(id) {
            if (this.busy) return
            this.error = null
            this.categoryId = id
            try { localStorage.setItem('entry-category', id) } catch (e) {}
        },

        press(key) {
            if (this.busy) return
            this.error = null
            let value = this.digits
            const [whole, fraction] = value.split(',')
            if (key === 'del') value = value.slice(0, -1)
            else if (key === ',') { if (decimals > 0 && fraction === undefined) value = (value || '0') + ',' }
            else if (key === '000') { if (value && fraction === undefined) value = (value + '000').slice(0, 9) }
            else if (fraction !== undefined) { if (fraction.length < decimals) value += key }
            else if (! (value === '' && key === '0') && whole.length < 9) value += key
            this.digits = value
        },

        onKey(event) {
            if (! this.open || event.target.closest('input, textarea')) return
            if (/^[0-9]$/.test(event.key)) this.press(event.key)
            else if ((event.key === ',' || event.key === '.') && decimals > 0) this.press(',')
            else if (event.key === 'Backspace') this.press('del')
            else if (event.key === 'Enter') this.submit()
            else if (event.key === 'Escape') this.close()
        },

        /** The typed amount in minor units, from the digits only (no float math). */
        minorAmount() {
            const [whole, fraction = ''] = this.digits.split(',')
            return parseInt(whole || '0', 10) * minor + parseInt((fraction + '0'.repeat(decimals)).slice(0, decimals) || '0', 10)
        },

        hasAmount() {
            return this.minorAmount() > 0
        },

        formatted() {
            const [whole, fraction] = this.digits.split(',')
            return this.numberFormat.format(parseInt(whole || '0', 10)) + (fraction !== undefined ? ',' + fraction : '')
        },

        category() {
            return this.categories.find(c => c.id === this.categoryId)
        },

        left() {
            const c = this.category()
            return c ? c.remaining - this.minorAmount() : 0
        },

        money(minorAmount) {
            return this.moneyFormat.format(minorAmount / minor) + ' ' + symbol
        },

        hint() {
            const c = this.category()
            if (! this.digits || ! c) return this.texts.empty
            if (c.planned <= 0) return c.name
            const left = this.left()
            return left >= 0
                ? this.texts.left.replace(':category', c.name).replace(':amount', this.money(left))
                : this.texts.over.replace(':category', c.name).replace(':amount', this.money(-left))
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
            if (! this.hasAmount() || this.busy || ! this.categoryId) return
            this.busy = true
            this.error = null
            const form = { category: this.categoryId, amount: this.digits, date: this.date, note: this.note || null }
            // A retry of the same form keeps its UUID, so a save that did reach the server is not recorded twice.
            const key = JSON.stringify(form)
            if (this.pending?.key !== key) this.pending = { key, uuid: this.uuid() }
            try {
                const result = await $wire.save({ ...form, clientUuid: this.pending.uuid })
                if (result?.ok) {
                    this.pending = null
                    navigator.vibrate?.(30)
                    this.close()
                    return
                }
                const messages = Object.values(result?.errors ?? {}).filter(Boolean)
                this.error = messages.length ? messages.join(' ') : this.texts.failed
            } catch (e) {
                this.error = this.texts.failed
            } finally {
                this.busy = false
            }
        },

        async noSpend() {
            if (this.busy) return
            this.busy = true
            this.error = null
            const wasMarked = $wire.noSpendMarked
            try {
                await $wire.toggleNoSpend()
                if (! wasMarked && $wire.noSpendMarked) this.close()
            } catch (e) {
                this.error = this.texts.failed
            } finally {
                this.busy = false
            }
        },
    }))
</script>
@endscript
