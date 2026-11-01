<?php

use App\Actions\Investments\SaveInvestmentAccount;
use App\Actions\Investments\SetAccountValue;
use App\Enums\AccountType;
use App\Enums\Currency;
use App\Enums\InvestmentProvider;
use App\Jobs\SyncInvestment;
use App\Models\Account;
use App\Models\User;
use App\Services\ExchangeRates\LatestRates;
use App\Services\InvestmentTotal;
use App\Services\InvestmentTrend;
use App\Services\PeriodService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/*
 * My money → Investments: each investment account with its value in its own currency, the same
 * in the base currency below it, and its trend. Values are typed in, or read daily from IBKR.
 */
new class extends Component {
    /** Days of history on a card's trend. */
    private const int TREND_DAYS = 365;

    /** A background refresh was running at the last render: when it ends, the tab total follows. */
    public bool $wasRefreshing = false;

    public function rendering(): void
    {
        if ($this->wasRefreshing && $this->refreshing === []) {
            unset($this->accounts);
            $this->dispatchTotal();
        }

        $this->wasRefreshing = $this->refreshing !== [];
    }

    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function accounts(): Collection
    {
        $since = app(PeriodService::class)->today($this->user()->settings())->subDays(self::TREND_DAYS)->toDateString();

        return $this->user()->accounts()->where('type', AccountType::Investment)
            ->with(['values' => fn ($query) => $query->whereDate('date', '>=', $since)->orderBy('date')])
            ->orderBy('name')->get();
    }

    /**
     * Accounts with a background refresh running (the cards then poll).
     *
     * @return list<int>
     */
    #[Computed]
    public function refreshing(): array
    {
        return $this->accounts->filter(fn (Account $account): bool => Cache::has(SyncInvestment::pendingKey($account->id)))->map(fn (Account $account): int => $account->id)->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function accountData(?int $accountId = null): array
    {
        $this->ensurePlusz();
        $account = $accountId === null ? null : $this->investment($accountId);

        return [
            'id' => $account?->id,
            'name' => $account->name ?? '',
            'currency' => ($account->currency ?? Currency::USD)->value,
            'provider' => ($account->provider ?? InvestmentProvider::Manual)->value,
            'queryId' => $account->credentials['query_id'] ?? '',
            'hasToken' => filled($account->credentials['token'] ?? null),
            'token' => '',
            'amounts' => ['value' => ''],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function save(array $data, SaveInvestmentAccount $saveInvestmentAccount, SetAccountValue $setAccountValue): array
    {
        $this->ensurePlusz();
        $account = isset($data['id']) && is_numeric($data['id']) ? $this->investment((int) $data['id']) : null;
        $provider = InvestmentProvider::tryFrom((string) ($data['provider'] ?? ''));
        $needsToken = $provider === InvestmentProvider::Ibkr && ! filled($account?->credentials['token'] ?? null);

        $validator = validator($data, [
            'name' => ['required', 'string', 'max:80'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'provider' => ['required', Rule::enum(InvestmentProvider::class)],
            'token' => [Rule::requiredIf($needsToken), 'nullable', 'string', 'max:200'],
            'queryId' => [Rule::requiredIf($provider === InvestmentProvider::Ibkr), 'nullable', 'string', 'regex:/^\d{3,12}$/'],
        ], [
            'queryId.regex' => __('The Query ID is a number, e.g. 123456.'),
        ], [
            'name' => __('Name'),
            'token' => __('Token'),
            'queryId' => __('Query ID'),
        ]);

        if ($validator->fails()) {
            return ['ok' => false, 'errors' => array_map(fn (array $messages): string => $messages[0], $validator->errors()->toArray())];
        }

        $currency = Currency::from((string) $data['currency']);
        $value = $provider === InvestmentProvider::Manual ? $this->parse($data['amounts']['value'] ?? null, $currency) : null;

        if ($provider === InvestmentProvider::Manual && filled($data['amounts']['value'] ?? null) && $value === null) {
            return ['ok' => false, 'errors' => ['value' => __('Enter a valid amount.')]];
        }

        $saved = $saveInvestmentAccount->handle($this->user(), [
            'name' => (string) $data['name'],
            'currency' => $currency,
            'provider' => $provider ?? InvestmentProvider::Manual,
            'token' => is_string($data['token'] ?? null) ? $data['token'] : null,
            'query_id' => is_string($data['queryId'] ?? null) ? $data['queryId'] : null,
        ], $account);

        if ($value !== null) {
            $setAccountValue->handle($saved, $value);
        }

        if ($saved->provider === InvestmentProvider::Ibkr) {
            SyncInvestment::start($saved);
        }

        unset($this->accounts, $this->refreshing);
        $this->dispatchTotal();
        $this->dispatch('app-toast', title: __('Saved: :name', ['name' => $saved->name]), subtitle: $saved->provider === InvestmentProvider::Ibkr ? __('Reading the account from Interactive Brokers…') : null);

        return ['ok' => true, 'errors' => []];
    }

    /**
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function setValue(int $accountId, string $amount, SetAccountValue $setAccountValue): array
    {
        $this->ensurePlusz();
        $account = $this->investment($accountId);
        $value = $this->parse($amount, $account->currency);

        if ($value === null) {
            return ['ok' => false, 'errors' => ['value' => __('Enter a valid amount.')]];
        }

        try {
            $setAccountValue->handle($account, $value);
        } catch (ValidationException $exception) {
            return ['ok' => false, 'errors' => ['value' => (string) collect($exception->errors())->flatten()->first()]];
        }

        unset($this->accounts);
        $this->dispatchTotal();

        return ['ok' => true, 'errors' => []];
    }

    public function refresh(int $accountId): void
    {
        $this->ensurePlusz();
        $account = $this->investment($accountId);

        if ($account->provider === InvestmentProvider::Ibkr) {
            SyncInvestment::start($account);
        }

        unset($this->refreshing);
    }

    public function delete(int $accountId): void
    {
        $this->ensurePlusz();
        $account = $this->investment($accountId);
        $name = $account->name;
        $account->delete();

        unset($this->accounts, $this->refreshing);
        $this->dispatchTotal();
        $this->dispatch('app-toast', title: __('Deleted: :name', ['name' => $name]));
    }

    /**
     * Tells the My money tab the new total, without re-rendering the page (it keeps its tab).
     */
    private function dispatchTotal(): void
    {
        $total = app(InvestmentTotal::class)->forUser($this->user());
        $this->dispatch('investments-total', total: $total !== null ? money_number($total) : '–');
    }

    /**
     * The value in the base currency at today's official rate, or null without rates.
     */
    public function inBase(Account $account): ?int
    {
        return $account->value === null ? null : app(LatestRates::class)->convert($account->value, $account->currency, user_currency());
    }

    private function investment(int $accountId): Account
    {
        return $this->user()->accounts()->where('type', AccountType::Investment)->findOrFail($accountId);
    }

    private function parse(mixed $value, Currency $currency): ?int
    {
        return is_scalar($value) && (string) $value !== '' ? Money::parse((string) $value, $currency) : null;
    }

    private function ensurePlusz(): void
    {
        abort_unless($this->user()->hasPlusz(), 403);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $accounts = $this->accounts;
    $refreshing = $this->refreshing;
    $trend = app(InvestmentTrend::class);
    $base = user_currency();
@endphp

<div x-data="investments({ locale: @js(str_replace('_', '-', app()->getLocale())), decimals: @js(collect(Currency::cases())->mapWithKeys(fn ($c) => [$c->value => $c->decimals()])) })"
     x-on:add-investment.window="openAccount(null)" @if ($refreshing !== []) wire:poll.4s @endif data-test="investments">
    @forelse ($accounts as $account)
        @php
            $values = $account->values->pluck('value')->all();
            $curve = $trend->build($values);
            $first = $values[0] ?? null;
            $change = $first !== null && $first > 0 && $account->value !== null ? ($account->value - $first) / $first * 100 : null;
            $inBase = $this->inBase($account);
            $isRefreshing = in_array($account->id, $refreshing, true);
        @endphp
        <button type="button" x-on:click="openAccount({{ $account->id }})" wire:key="investment-{{ $account->id }}"
                class="press mx-4 mt-3 block w-[calc(100%-2rem)] overflow-hidden rounded-[28px] bg-hero pt-5 text-left first:mt-4" data-test="investment-{{ $account->id }}">
            <div class="px-5">
                <div class="flex items-center justify-between gap-3">
                    <span class="truncate text-[15px] text-hero-sub">{{ $account->name }}</span>
                    <span class="shrink-0 text-xs text-hero-sub">{{ $account->provider->label() }}</span>
                </div>
                @if ($account->value !== null)
                    <div class="num mt-1 flex items-baseline gap-1.5">
                        <span class="text-[44px] leading-none font-extrabold tracking-[-0.045em] text-hero-ink">{{ money_number($account->value, $account->currency) }}</span>
                        <span class="text-xl text-hero-sub">{{ $account->currency->symbol() }}</span>
                    </div>
                    <div class="num mt-1.5 flex flex-wrap items-center gap-x-2 text-[13px] text-hero-soft">
                        @if ($account->currency !== $base && $inBase !== null)<span data-test="investment-in-base">≈ {{ money($inBase) }}</span>@endif
                        @if ($change !== null && count($values) > 1)
                            <span @class(['font-semibold', 'text-accent' => $change >= 0, 'text-danger-soft' => $change < 0])>{{ ($change >= 0 ? '+' : '−').number_format(abs($change), 1, ',', ' ') }}%</span>
                        @endif
                    </div>
                @else
                    <div class="mt-2 text-[15px] text-hero-soft">{{ $isRefreshing ? __('Reading the account…') : __('No value yet') }}</div>
                @endif
            </div>
            @if ($curve)
                <svg viewBox="0 0 {{ $curve::WIDTH }} {{ $curve::HEIGHT }}" role="img" @class(['mt-2 block h-auto w-full', 'text-accent' => ($change ?? 0) >= 0, 'text-danger' => ($change ?? 0) < 0])
                     aria-label="{{ __('From :from to :to', ['from' => money($first ?? 0, $account->currency), 'to' => money((int) $account->value, $account->currency)]) }}" data-test="investment-trend">
                    <path d="{{ $curve->area() }}" fill="currentColor" fill-opacity=".14" />
                    <line x1="0" y1="{{ $curve->planY }}" x2="{{ $curve::WIDTH }}" y2="{{ $curve->planY }}" stroke="var(--app-hero-sub)" stroke-opacity=".35" stroke-dasharray="2 4" />
                    <path d="{{ $curve->line() }}" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" class="curve-draw" />
                    <circle cx="{{ $curve->endX }}" cy="{{ $curve->endY }}" r="4" fill="var(--app-hero)" stroke="currentColor" stroke-width="2" />
                    <text x="18" y="114" fill="var(--app-hero-sub)" fill-opacity=".7" font-size="10">{{ \App\Support\Dates::short($account->values->first()->date) }}</text>
                    <text x="{{ $curve::WIDTH - 18 }}" y="114" text-anchor="end" fill="var(--app-hero-sub)" fill-opacity=".7" font-size="10">{{ \App\Support\Dates::short($account->values->last()->date) }}</text>
                </svg>
            @else
                <div class="h-5"></div>
            @endif
            <div class="flex items-center gap-1.5 border-t border-hero-2 px-5 py-3 text-xs">
                @if ($isRefreshing)
                    <span class="text-hero-sub">{{ __('Refreshing…') }}</span>
                @elseif ($account->sync_error)
                    <x-ui.icon name="error" :size="16" class="shrink-0 text-danger-soft" />
                    <span class="min-w-0 text-danger-soft" data-test="investment-error">{{ $account->sync_error }}</span>
                @elseif ($account->valued_on)
                    <span class="text-hero-sub">{{ $account->provider === InvestmentProvider::Ibkr ? __('Value on :date, read every morning', ['date' => \App\Support\Dates::short($account->valued_on)]) : __('Value on :date', ['date' => \App\Support\Dates::short($account->valued_on)]) }}</span>
                @else
                    <span class="text-hero-sub">{{ __('Tap to type in its value or connect it') }}</span>
                @endif
            </div>
        </button>
    @empty
        <div class="mx-4 mt-4 rounded-[28px] bg-surface px-5 py-6 text-center" data-test="investments-empty">
            <x-ui.icon-tile icon="show_chart" tone="accent" :size="48" class="mx-auto" />
            <div class="mt-3 text-[17px] font-semibold">{{ __('Your investments in one place') }}</div>
            <p class="mt-1.5 text-sm leading-normal text-muted">{{ __('Add an account and type in its value, or connect Interactive Brokers and it updates itself every morning. Investments are not part of the month: the month-end leftover does not count them.') }}</p>
            <x-ui.button class="mt-4 w-full" icon="add" x-on:click="openAccount(null)" data-test="add-investment">{{ __('Add an investment account') }}</x-ui.button>
        </div>
    @endforelse

    @if ($accounts->isNotEmpty())
        <p class="mx-6 mt-3 text-xs leading-snug text-muted">{{ __('Investments are not part of the month: the month-end leftover does not count them. Converted at today\'s MNB rate.') }}</p>
    @endif

    <x-ui.form-sheet show="sheet" close="sheet = false" title="title()" data-test="investment-sheet">
        <x-slot:action>
            <button type="button" x-show="form.id" x-on:click="remove()" class="text-danger" aria-label="{{ __('Delete') }}"><x-ui.icon name="delete" :size="22" /></button>
        </x-slot:action>

        <x-ui.form-group :title="__('Where does the value come from?')">
            <div class="p-3">
                <x-ui.segmented model="form.provider" :options="[InvestmentProvider::Manual->value => __('I type it in'), InvestmentProvider::Ibkr->value => __('Interactive Brokers')]" />
                <p class="mt-2.5 px-0.5 text-xs leading-snug text-muted" x-show="form.provider === 'manual'">{{ __('For any broker, e.g. ActivTrades or Lightyear: you update the value when you look at it.') }}</p>
                <p class="mt-2.5 px-0.5 text-xs leading-snug text-muted" x-show="form.provider === 'ibkr'">{{ __('Read every morning with a read-only report token: it cannot trade or move money, and you can revoke it at IBKR any time.') }}</p>
            </div>
        </x-ui.form-group>

        <x-ui.form-group :title="__('Basics')">
            <x-ui.text-row :label="__('Name')" x-model="form.name" maxlength="80" :placeholder="__('e.g. IBKR')" error="name" data-test="investment-name" />
            <div x-show="form.provider === 'manual'">
                <x-ui.select-row :label="__('Currency')" x-model="form.currency">
                    @foreach (Currency::cases() as $currency)<option value="{{ $currency->value }}">{{ $currency->value }}</option>@endforeach
                </x-ui.select-row>
            </div>
            <x-ui.amount-row name="value" :label="__('Value now')" x-show="form.provider === 'manual'" error="value" data-test="investment-value" />
        </x-ui.form-group>

        <template x-if="form.provider === 'ibkr'">
            <div>
                <x-ui.form-group :title="__('Interactive Brokers')">
                    <x-ui.text-row :label="__('Query ID')" x-model="form.queryId" inputmode="numeric" :placeholder="__('e.g. 123456')" error="queryId" data-test="investment-query" />
                    <x-ui.text-row :label="__('Token')" x-model="form.token" autocomplete="off" ::placeholder="tokenPlaceholder()" error="token" data-test="investment-token" />
                </x-ui.form-group>
                <section class="mt-5 rounded-2xl bg-surface-2 px-4 py-3.5 text-[13px] leading-normal text-ink-2">
                    <div class="font-semibold text-ink">{{ __('How to get them') }}</div>
                    <ol class="mt-1.5 list-decimal space-y-1 pl-4">
                        <li>{{ __('In the IBKR Client Portal: Performance & Reports → Flex Queries → Activity Flex Query, +.') }}</li>
                        <li>{{ __('Add the section "Net Asset Value (NAV) in Base" with all its fields.') }}</li>
                        <li>{{ __('Format: XML, period: Last 365 Calendar Days, date format: the default (yyyyMMdd). Save it; its number is the Query ID.') }}</li>
                        <li>{{ __('Under Flex Web Service, turn it on and generate the token; paste it here.') }}</li>
                    </ol>
                </section>
            </div>
        </template>

        <x-slot:footer>
            <div class="flex gap-2">
                <x-ui.button variant="secondary" x-show="form.id && form.provider === 'ibkr'" x-on:click="$wire.refresh(form.id); sheet = false" icon="refresh" data-test="investment-refresh">{{ __('Refresh') }}</x-ui.button>
                <x-ui.button x-on:click="submit()" ::disabled="saving" class="flex-1" data-test="investment-save">{{ __('Save') }}</x-ui.button>
            </div>
        </x-slot:footer>
        <x-slot:pad><x-ui.amount-pad :decimal="true" /></x-slot:pad>
    </x-ui.form-sheet>
</div>

@script
<script>
    Alpine.data('investments', ({ locale, decimals }) => ({
        sheet: false,
        form: {},
        fields: {},
        active: null,
        activeLabel: '',
        errors: {},
        saving: false,
        formatter: new Intl.NumberFormat(locale, { maximumFractionDigits: 2, useGrouping: 'always' }),

        async openAccount(id) {
            const data = await $wire.accountData(id)
            this.form = data
            this.fields = { ...data.amounts }
            this.errors = {}
            this.active = null
            this.sheet = true
        },
        tokenPlaceholder() { return this.form.hasToken ? @js(__('Saved, leave empty')) : @js(__('Paste it here')) },
        title() { return this.form.id ? this.form.name : @js(__('New investment account')) },
        focus(name, label = '') { this.active = name; this.activeLabel = label },
        press(key) {
            if (! this.active) return
            const places = decimals[this.form.currency] ?? 0
            let value = String(this.fields[this.active] ?? '')
            if (key === 'del') value = value.slice(0, -1)
            else if (key === ',') { if (places > 0 && ! value.includes(',')) value = (value || '0') + ',' }
            else if (key === '000') { if (value && ! value.includes(',')) value += '000' }
            else {
                const fraction = value.split(',')[1]
                if (fraction !== undefined && fraction.length >= places) return
                if (value === '0') value = ''
                value += key
            }
            this.fields[this.active] = value.slice(0, 15)
        },
        display(name, fallback = '–') {
            const value = String(this.fields[name] ?? '')
            if (value === '') return fallback
            const [whole, fraction] = value.split(',')
            return this.formatter.format(parseInt(whole || '0', 10)) + (fraction !== undefined ? ',' + fraction : '')
        },
        filled(name) { return String(this.fields[name] ?? '') !== '' },
        async submit() {
            this.saving = true
            try {
                const result = await $wire.save({ ...this.form, amounts: { ...this.fields } })
                this.errors = result.errors
                if (result.ok) this.sheet = false
            } finally {
                this.saving = false
            }
        },
        async remove() {
            if (! await window.appConfirm({ title: @js(__('Delete this investment account?')), body: @js(__('Its value and trend are deleted. Nothing changes at the broker.')), confirm: @js(__('Delete')), danger: true })) return
            await $wire.delete(this.form.id)
            this.sheet = false
        },
    }))
</script>
@endscript
