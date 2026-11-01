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

        // Only accounts that are tracked: connected, or with a value typed in. The investment
        // account picked at setup as a closing target stays out until it gets one.
        return $this->user()->accounts()->where('type', AccountType::Investment)
            ->where(fn ($query) => $query->where('provider', InvestmentProvider::Ibkr)->orWhereNotNull('value'))
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
            'current' => $account?->value !== null ? money_number($account->value, $account->currency) : null,
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
    @foreach ($accounts as $account)
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
                    <span class="text-hero-sub">{{ __('Last updated: :date', ['date' => \App\Support\Dates::short($account->valued_on)]) }}</span>
                @else
                    <span class="text-hero-sub">{{ __('Reading the account…') }}</span>
                @endif
            </div>
        </button>
    @endforeach

    @if ($accounts->isEmpty())
        <div class="flex flex-col items-center px-8 pt-16 text-center" data-test="investments-empty">
            <p class="text-[15px] text-muted">{{ __('You have no investment account yet.') }}</p>
            <x-ui.button class="mt-4" icon="add" x-on:click="openAccount(null)" data-test="add-investment">{{ __('Account') }}</x-ui.button>
        </div>
    @else
        <p class="mx-6 mt-3 text-xs leading-snug text-muted">{{ __('Investments are not part of the month: the month-end leftover does not count them. Converted at today\'s MNB rate.') }}</p>
    @endif

    <x-ui.form-sheet show="sheet" close="sheet = false" title="title()" :full="false" data-test="investment-sheet">
        <x-slot:action>
            <button type="button" x-show="step === 'edit'" x-on:click="remove()" class="text-danger" aria-label="{{ __('Delete') }}"><x-ui.icon name="delete" :size="22" /></button>
        </x-slot:action>

        {{-- 1. What kind of account: one row each, more brokers come here later. --}}
        <div x-show="step === 'type'" class="divide-y divide-line overflow-hidden rounded-2xl bg-surface-2" data-test="investment-types">
            <button type="button" x-on:click="choose('manual')" class="flex w-full items-center gap-3 px-4 py-3.5 text-left active:bg-ink/4" data-test="type-manual">
                <x-ui.icon-tile icon="edit" :size="40" />
                <span class="min-w-0 flex-1 text-[15px] font-medium">{{ __('Manual') }}</span>
                <x-ui.icon name="chevron_right" :size="22" class="text-faint" />
            </button>
            <button type="button" x-on:click="choose('ibkr')" class="flex w-full items-center gap-3 px-4 py-3.5 text-left active:bg-ink/4" data-test="type-ibkr">
                <x-ui.icon-tile icon="show_chart" tone="accent" :size="40" />
                <span class="min-w-0 flex-1 text-[15px] font-medium">{{ __('Interactive Brokers') }}</span>
                <x-ui.icon name="chevron_right" :size="22" class="text-faint" />
            </button>
        </div>

        {{-- 2a. Typed in by hand (and editing any account): the value on top, typed on the numpad below, like a spending. --}}
        <div x-show="step === 'manual' || step === 'edit'">
            <template x-if="form.provider === 'manual'">
                <div class="pb-3 pt-2 text-center" data-test="investment-value">
                    <div class="num text-[44px] font-semibold tracking-[-0.03em]" :class="filled('value') ? '' : 'text-faint'">
                        <span x-text="display('value', form.current || '0')"></span> <span class="text-[22px] text-muted" x-text="form.currency"></span>
                    </div>
                    <p class="text-xs text-danger" x-show="errors.value" x-text="errors.value"></p>
                </div>
            </template>
            <x-ui.form-group>
                <x-ui.text-row :label="__('Name')" x-model="form.name" maxlength="80" :placeholder="__('e.g. ActivTrades')" error="name" data-test="investment-name" />
                <div x-show="form.provider === 'manual'">
                    <x-ui.select-row :label="__('Currency')" x-model="form.currency">
                        @foreach (Currency::cases() as $currency)<option value="{{ $currency->value }}">{{ $currency->value }}</option>@endforeach
                    </x-ui.select-row>
                </div>
                <template x-if="form.provider === 'ibkr'">
                    <div class="divide-y divide-line">
                        <x-ui.text-row :label="__('Query ID')" x-model="form.queryId" inputmode="numeric" error="queryId" />
                        <x-ui.text-row :label="__('Token')" x-model="form.token" autocomplete="off" ::placeholder="tokenPlaceholder()" error="token" />
                    </div>
                </template>
            </x-ui.form-group>
            <template x-if="form.provider === 'manual'">
                <x-ui.numpad :decimal="true" class="mt-4" data-test="investment-numpad" />
            </template>
        </div>

        {{-- 2b. Interactive Brokers, one step at a time. --}}
        <div x-show="step === 'ibkr'" data-test="ibkr-steps">
            <div class="flex gap-1.5 px-1 pt-1" aria-hidden="true">
                <template x-for="i in 5"><span class="h-1 flex-1 rounded-full" :class="i <= ibkrStep ? 'bg-accent' : 'bg-surface-3'"></span></template>
            </div>
            @foreach ([
                1 => [__('Open Flex Queries'), __('In the IBKR Client Portal: Performance & Reports → Flex Queries. Next to Activity Flex Query, tap +.')],
                2 => [__('Add one section'), __('Choose "Net Asset Value (NAV) in Base" and select all its fields.')],
                3 => [__('Set it up like this, then save'), null],
                4 => [__('Copy the Query ID'), __('Next to the saved query, tap the (i) button: it shows the Query ID.')],
                5 => [__('Make a token'), __('Open the Flex Web Service setting, turn it on and generate a token. It can only read reports: it cannot trade or move money.')],
            ] as $number => [$heading, $body])
                <div x-show="ibkrStep === {{ $number }}" class="px-2 pt-6" data-test="ibkr-step-{{ $number }}">
                    <h3 class="text-[24px] font-semibold leading-tight tracking-[-0.02em]">{{ $heading }}</h3>
                    @if ($body)<p class="mt-2 text-[15px] leading-normal text-ink-2">{{ $body }}</p>@endif
                </div>
            @endforeach
            <div x-show="ibkrStep === 3">
                <x-ui.form-group class="!mt-5">
                    @foreach ([__('IBKR: Query Name') => 'MoneySight', __('IBKR: Format') => 'XML', __('IBKR: Period') => 'Last 365 Calendar Days', __('IBKR: Date Format') => 'yyyyMMdd'] as $setting => $value)
                        <div class="flex min-h-[52px] items-center justify-between gap-3 px-4 py-2.5"><span class="text-[15px] text-ink-2">{{ $setting }}</span><span class="num text-right text-[15px] font-semibold">{{ $value }}</span></div>
                    @endforeach
                </x-ui.form-group>
            </div>
            <div x-show="ibkrStep === 4">
                <x-ui.form-group class="!mt-5">
                    <x-ui.text-row :label="__('Query ID')" x-model="form.queryId" inputmode="numeric" :placeholder="__('e.g. 123456')" error="queryId" data-test="investment-query" />
                </x-ui.form-group>
            </div>
            <div x-show="ibkrStep === 5">
                {{-- Another site: the installed app opens it in the browser, the setup stays where it was. --}}
                <a href="https://www.interactivebrokers.com/AccountManagement/amAuthentication?action=AccountSetting&config=FlexWebService" target="_blank" rel="noopener noreferrer"
                   class="press mx-2 mt-4 flex h-11 items-center justify-center gap-1.5 rounded-btn bg-surface-2 text-[15px] font-semibold" data-test="ibkr-flex-web-service">
                    {{ __('Open Flex Web Service') }}<x-ui.icon name="open_in_new" :size="18" />
                </a>
                <x-ui.form-group class="!mt-5">
                    <x-ui.text-row :label="__('Token')" x-model="form.token" autocomplete="off" :placeholder="__('Paste it here')" error="token" data-test="investment-token" />
                    <x-ui.text-row :label="__('Name')" x-model="form.name" maxlength="80" error="name" data-test="investment-ibkr-name" />
                </x-ui.form-group>
            </div>
        </div>

        <x-slot:footer>
            <div class="flex gap-2" x-show="step !== 'type'">
                <x-ui.button variant="secondary" x-show="step === 'manual' || (step === 'ibkr')" x-on:click="back()" data-test="investment-back">{{ __('Back') }}</x-ui.button>
                <x-ui.button variant="secondary" x-show="step === 'edit' && form.provider === 'ibkr'" x-on:click="$wire.refresh(form.id); sheet = false" icon="refresh" data-test="investment-refresh">{{ __('Refresh') }}</x-ui.button>
                <x-ui.button x-show="step === 'ibkr' && ibkrStep < 5" x-on:click="next()" class="flex-1" data-test="investment-next">{{ __('Next') }}</x-ui.button>
                <x-ui.button x-show="step !== 'ibkr' || ibkrStep === 5" x-on:click="submit()" ::disabled="saving" class="flex-1" data-test="investment-save"><span x-text="step === 'ibkr' ? @js(__('Connect')) : @js(__('Save'))"></span></x-ui.button>
            </div>
        </x-slot:footer>
    </x-ui.form-sheet>
</div>

@script
<script>
    Alpine.data('investments', ({ locale, decimals }) => ({
        sheet: false,
        step: 'type',
        ibkrStep: 1,
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
            this.step = id ? 'edit' : 'type'
            this.ibkrStep = 1
            this.sheet = true
        },
        choose(provider) {
            if (this.form.name === @js(__('Interactive Brokers'))) { this.form.name = ''; }
            this.form.provider = provider
            if (provider === 'ibkr' && ! this.form.name) { this.form.name = @js(__('Interactive Brokers')); }
            this.step = provider
            this.ibkrStep = 1
        },
        next() {
            if (this.ibkrStep === 4 && ! /^\d{3,12}$/.test(String(this.form.queryId || '').trim())) {
                this.errors = { queryId: @js(__('The Query ID is a number, e.g. 123456.')) }
                return
            }
            this.errors = {}
            this.ibkrStep++
        },
        back() {
            if (this.step === 'ibkr' && this.ibkrStep > 1) { this.ibkrStep--; return }
            this.errors = {}
            this.step = 'type'
        },
        tokenPlaceholder() { return this.form.hasToken ? @js(__('Saved, leave empty')) : @js(__('Paste it here')) },
        title() { return this.step === 'edit' ? this.form.name : @js(__('New investment account')) },
        focus(name, label = '') { this.active = name; this.activeLabel = label },
        press(key) {
            const field = this.active ?? 'value'
            const places = decimals[this.form.currency] ?? 0
            let value = String(this.fields[field] ?? '')
            if (key === 'del') value = value.slice(0, -1)
            else if (key === ',') { if (places > 0 && ! value.includes(',')) value = (value || '0') + ',' }
            else if (key === '000') { if (value && ! value.includes(',')) value += '000' }
            else {
                const fraction = value.split(',')[1]
                if (fraction !== undefined && fraction.length >= places) return
                if (value === '0') value = ''
                value += key
            }
            this.fields[field] = value.slice(0, 15)
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
                if (this.step === 'ibkr' && result.errors.queryId) this.ibkrStep = 4
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
