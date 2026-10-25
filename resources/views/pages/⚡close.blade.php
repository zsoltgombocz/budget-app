<?php

use App\Actions\Budget\RecordPrepayment;
use App\Enums\CalcMode;
use App\Enums\LineType;
use App\Models\Period;
use App\Models\PeriodClose;
use App\Models\User;
use App\Services\Data\ClosePreview;
use App\Services\PeriodCloser;
use App\Services\PeriodService;
use App\Services\PlanService;
use App\Support\Dates;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Close the month')] #[Layout('layouts::app', ['tabs' => false])] class extends Component {
    #[Locked]
    public int $periodId;

    public int $step = 1;

    /** Actual income in the smallest unit. */
    public int $incomeActual = 0;

    /** Manual split of a positive leftover, null = the settings' rule. */
    public ?int $toReserve = null;

    /** Leftover target for this closing only ('account:ID', 'pocket:ID', 'new-pocket', 'none'); null = settings. */
    public ?string $surplusTarget = null;

    public bool $saveTargetAsDefault = false;

    /** @var list<int> pockets whose prepayment was recorded after closing */
    public array $prepaid = [];

    public function mount(int $period): void
    {
        $model = $this->user()->periods()->findOrFail($period);
        $this->periodId = $model->id;
        $this->incomeActual = $model->income();
        $this->step = $model->isOpen() ? 1 : 4;
    }

    /**
     * The closing is one screen now; kept so old links and tests that walk the steps still land on it.
     */
    public function goTo(int $step): void
    {
        if ($this->period->isOpen()) {
            $this->step = 1;
            unset($this->preview);
        }
    }

    /**
     * "Hová kerüljön?": where a positive leftover goes. Nothing moves until "Lezárom".
     */
    public function choose(string $option): void
    {
        $leftover = max(0, $this->preview->leftover());
        $prepay = $this->prepayPocket;

        match ($option) {
            'reserve' => [$this->toReserve, $this->surplusTarget] = [$leftover, 'none'],
            'prepay' => [$this->toReserve, $this->surplusTarget] = [0, $prepay !== null ? 'pocket:'.$prepay->id : 'none'],
            'target' => [$this->toReserve, $this->surplusTarget] = [0, $this->defaultTarget],
            'split' => [$this->toReserve, $this->surplusTarget] = [(int) ceil($leftover / 2), $prepay !== null ? 'pocket:'.$prepay->id : $this->defaultTarget],
            default => [$this->toReserve, $this->surplusTarget] = [0, 'none'],
        };

        unset($this->preview);
    }

    /**
     * The pocket that saves up for a loan prepayment, if there is one.
     */
    #[Computed]
    public function prepayPocket(): ?\App\Models\Pocket
    {
        return $this->user()->pockets()->where('is_reserve', false)->whereNotNull('loan_id')->whereNotNull('prepay_step')->orderBy('sort')->first();
    }

    /**
     * Which option the current split matches.
     */
    public function chosen(ClosePreview $preview): string
    {
        $allocation = $preview->allocation;
        $target = $preview->surplusTarget;
        $targetKey = in_array($target['type'], ['account', 'pocket'], true) ? $target['type'].':'.$target['id'] : ($target['type'] ?? 'none');
        $prepayKey = $this->prepayPocket !== null ? 'pocket:'.$this->prepayPocket->id : null;

        return match (true) {
            $preview->reservePocketId !== null && $allocation->toSurplus === 0 && $allocation->toReserve > 0 => 'reserve',
            $allocation->toReserve > 0 => 'split',
            $prepayKey !== null && $targetKey === $prepayKey => 'prepay',
            $targetKey === 'none' => 'stays',
            default => 'target',
        };
    }

    public function chooseTarget(string $target): void
    {
        $user = $this->user();
        $valid = $target === 'none' || $target === 'new-pocket'
            || (preg_match('/^account:(\d+)$/', $target, $m) === 1 && $user->accounts()->whereKey((int) $m[1])->exists())
            || (preg_match('/^pocket:(\d+)$/', $target, $m) === 1 && $user->pockets()->whereKey((int) $m[1])->where('is_reserve', false)->exists());

        if ($valid) {
            $this->surplusTarget = $target;
            unset($this->preview);
        }
    }

    public function split(int $toReserve): void
    {
        $this->toReserve = max(0, $toReserve);
        unset($this->preview);
    }

    /**
     * Sets one side of the leftover split from the numpad; the other side gets the rest.
     *
     * @return array{ok: bool, error: string|null}
     */
    public function setSplit(string $part, string $value): array
    {
        $amount = Money::parse($value, user_currency());
        $leftover = max(0, $this->preview->leftover());

        if ($amount === null || $amount > $leftover) {
            return ['ok' => false, 'error' => __('Enter an amount between 0 and :max.', ['max' => money($leftover)])];
        }

        $this->split($part === 'reserve' ? $amount : $leftover - $amount);

        return ['ok' => true, 'error' => null];
    }

    /**
     * @return array{ok: bool, error: string|null}
     */
    public function setIncome(string $value): array
    {
        $income = Money::parse($value, user_currency());

        if ($income === null) {
            return ['ok' => false, 'error' => __('Enter a valid amount.')];
        }

        $this->incomeActual = $income;
        $this->toReserve = null;
        unset($this->preview);

        return ['ok' => true, 'error' => null];
    }

    public function close(PeriodCloser $closer): void
    {
        $user = $this->user();
        $closer->close($user, $this->period, $this->incomeActual, $this->toReserve, $this->surplusTarget);

        if ($this->saveTargetAsDefault && $this->surplusTarget !== null) {
            [$type, $id] = array_pad(explode(':', $this->surplusTarget, 2), 2, null);

            if ($type === 'new-pocket') {
                [$type, $id] = ['pocket', $user->pockets()->where('name', __('Savings'))->latest('id')->value('id')];
            }

            $user->settings()->update([
                'surplus_account_id' => $type === 'account' ? (int) $id : null,
                'surplus_pocket_id' => $type === 'pocket' && $id !== null ? (int) $id : null,
            ]);
        }

        $this->step = 4;
        unset($this->period, $this->preview, $this->closeRecord);

        $this->dispatch('app-toast', title: __('Period closed. A new one has started.'));
    }

    public function prepay(int $pocketId, RecordPrepayment $recordPrepayment): void
    {
        $user = $this->user();
        abort_unless($user->hasPlusz(), 403);
        $pocket = $user->pockets()->findOrFail($pocketId);
        $loan = $pocket->loan_id !== null ? $user->loans()->find($pocket->loan_id) : null;

        abort_if($loan === null || $pocket->prepay_step === null, 404);

        $recordPrepayment->handle($user, $loan, min($pocket->prepay_step, $pocket->balance, $loan->principal_balance), $pocket);
        $this->prepaid[] = $pocket->id;

        $this->dispatch('app-toast', title: __('Prepayment recorded.'), subtitle: __('New installment: :amount', ['amount' => money($loan->refresh()->monthlyPayment())]));
    }

    #[Computed]
    public function period(): Period
    {
        return $this->user()->periods()->findOrFail($this->periodId);
    }

    #[Computed]
    public function preview(): ClosePreview
    {
        return app(PeriodCloser::class)->preview($this->user(), $this->period, $this->incomeActual, $this->toReserve, $this->surplusTarget);
    }

    #[Computed]
    public function closeRecord(): ?PeriodClose
    {
        return $this->period->close()->first();
    }

    /**
     * What the next period copies: income, fixed item count and the avg/max choices.
     */
    #[Computed]
    public function nextPeriodNote(): string
    {
        $lines = app(PlanService::class)->linesFor($this->period);
        $fixed = count(array_filter($lines, fn ($line) => $line->type !== LineType::Variable));
        $modes = array_map(
            fn ($line) => $line->categoryName.': '.($line->calcMode === CalcMode::Max ? __('Max') : __('Average')),
            array_filter($lines, fn ($line) => $line->type === LineType::Variable && $line->calcMode !== CalcMode::Fixed),
        );

        return __('The new plan is copied from this one: :income income, :count fixed items', ['income' => money($this->user()->settings()->income), 'count' => $fixed])
            .($modes !== [] ? ', '.implode(', ', $modes) : '').'.';
    }

    /**
     * @return array{0: \Carbon\CarbonImmutable, 1: \Carbon\CarbonImmutable}
     */
    #[Computed]
    public function nextBounds(): array
    {
        return app(PeriodCloser::class)->nextBounds($this->user(), $this->period);
    }

    /**
     * @return array<string, string> target key => label
     */
    #[Computed]
    public function targets(): array
    {
        $user = $this->user();
        $targets = [];

        foreach ($user->accounts()->orderBy('name')->get() as $account) {
            $targets['account:'.$account->id] = $account->name;
        }

        foreach ($user->pockets()->where('is_reserve', false)->orderBy('sort')->get() as $pocket) {
            $targets['pocket:'.$pocket->id] = $pocket->name;
        }

        $targets['new-pocket'] = __('New “Savings” pocket');
        $targets['none'] = __('Stays on the account');

        return $targets;
    }

    /**
     * The leftover target saved in the settings, as a target key.
     */
    #[Computed]
    public function defaultTarget(): string
    {
        $settings = $this->user()->settings();

        return match (true) {
            $settings->surplus_account_id !== null => 'account:'.$settings->surplus_account_id,
            $settings->surplus_pocket_id !== null => 'pocket:'.$settings->surplus_pocket_id,
            default => 'none',
        };
    }

    #[Computed]
    public function reserveBalance(): int
    {
        return (int) ($this->user()->pockets()->where('is_reserve', true)->value('balance') ?? 0);
    }

    #[Computed]
    public function canClose(): bool
    {
        return app(PeriodCloser::class)->canClose($this->user(), $this->period);
    }

    #[Computed]
    public function isEarly(): bool
    {
        return app(PeriodService::class)->today($this->user()->settings())->lessThan($this->period->ends_on);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

@php
    $period = $this->period;
    $preview = $step <= 3 ? $this->preview : null;
    $currency = user_currency();
    $month = Dates::monthName($period->nameDate());
@endphp

<div>
<div class="flex min-h-[calc(100dvh-var(--safe-top)-env(safe-area-inset-bottom))] flex-col pb-[calc(10rem+env(safe-area-inset-bottom))]"
     x-data="closeWizard({
        decimals: {{ $currency->decimals() }},
        locale: @js(str_replace('_', '-', app()->getLocale())),
        symbol: @js($currency->symbol()),
     })">
    <div wire:key="step-{{ $step }}" class="step-enter">

    @if ($step <= 3)
        @php
            $leftover = $preview->leftover();
            $allocation = $preview->allocation;
            $hasReserve = $preview->reservePocketId !== null;
            $prepayPocket = $this->prepayPocket;
            $deposits = collect($preview->pocketDeposits)->pluck('amount', 'pocket_id');
            $chosen = $this->chosen($preview);
            $reserveNow = $this->reserveBalance + (int) ($hasReserve ? ($deposits[$preview->reservePocketId] ?? 0) : 0);
            // What the budgets did not use up: it is part of what was left.
            $unspent = array_sum(array_map(fn (array $row): int => $row['type'] === 'variable' ? max(0, $row['planned'] - $row['actual']) : 0, $preview->categories));
            $prepayNow = $prepayPocket ? $prepayPocket->balance + (int) ($deposits[$prepayPocket->id] ?? 0) : 0;
            $defaultTarget = $this->defaultTarget;
            $targetName = $this->targets[$defaultTarget] ?? null;
            $options = [];
            if ($leftover > 0) {
                if ($hasReserve) {
                    $options['reserve'] = [__('To the reserve'), __(':amount now', ['amount' => money_number($reserveNow)]), money_number($reserveNow).' → '.money_number($reserveNow + $leftover)];
                }
                if ($prepayPocket && auth()->user()?->hasPlusz()) {
                    $after = $prepayNow + $leftover;
                    $options['prepay'] = [__('To the prepayment'), __(':amount now', ['amount' => money_number($prepayNow)]), money_number($prepayNow).' → '.money_number($after).($prepayPocket->prepay_step && $prepayNow < $prepayPocket->prepay_step && $after >= $prepayPocket->prepay_step ? ' · '.__(':step is there!', ['step' => money_number($prepayPocket->prepay_step)]) : '')];
                }
                if (auth()->user()?->hasPlusz() && ! in_array($defaultTarget, ['none', $prepayPocket ? 'pocket:'.$prepayPocket->id : ''], true) && $targetName) {
                    $targetHint = str_starts_with($defaultTarget, 'pocket:') ? __('into the pocket in the app') : __('we remind you to transfer it');
                    $options['target'] = [$targetName, $targetHint, $targetHint];
                }
                $options['stays'] = [__('Stays on the account'), __('nothing moves'), __('nothing moves')];
                if ($hasReserve && count($options) > 2) {
                    $options['split'] = [__('I split it'), __('two places, you set how much'), __('tap an amount to change it')];
                }
            }
            $splitOther = $preview->surplusTarget['name'] ?? __('Stays on the account');
        @endphp

        <div class="mt-6 flex h-11 items-center px-3">
            <a href="{{ route('month', ['periodus' => $period->id]) }}" wire:navigate class="-ml-1.5 inline-flex items-center gap-1 text-base text-ink-2"><x-ui.icon name="chevron_left" :size="22" />{{ __('Month') }}</a>
        </div>
        <div class="px-4">
            <h1 class="mt-3 text-[28px] font-bold tracking-[-0.02em]">{{ __(':month closing', ['month' => $month]) }}</h1>
            @if (! $this->canClose)
                <p class="mt-1 text-[13px] text-warn" data-test="close-not-yet">{{ __('You can close the month in its last week, from :date.', ['date' => Dates::short(app(PeriodCloser::class)->closableFrom($period))]) }}</p>
            @elseif ($this->isEarly)
                <p class="mt-1 text-[13px] text-muted">{{ __('The month has not ended yet: the new one starts today.') }}</p>
            @endif

            <section @class(['mt-4 rounded-[28px] px-5 pt-[22px] pb-5', 'bg-hero' => $leftover >= 0, 'bg-danger-tint' => $leftover < 0]) data-test="close-leftover">
                <div @class(['text-[15px]', 'text-hero-sub' => $leftover >= 0, 'text-danger-soft' => $leftover < 0])>{{ __('Was left') }}</div>
                <div class="num mt-1 flex items-baseline gap-1.5">
                    <x-ui.count-up :value="$leftover" @class(['text-[52px] leading-none font-extrabold tracking-[-0.045em]', 'text-hero-ink' => $leftover >= 0, 'text-danger' => $leftover < 0]) wire:key="left-{{ $leftover }}" />
                    <span @class(['text-[22px]', 'text-hero-sub' => $leftover >= 0, 'text-danger-soft' => $leftover < 0])>{{ $currency->symbol() }}</span>
                </div>
                <div @class(['num mt-2.5 flex items-center gap-2 text-sm', 'text-hero-soft' => $leftover >= 0, 'text-ink-2' => $leftover < 0])>
                    <span>{{ __(':amount income', ['amount' => money_number($preview->incomeActual)]) }}</span>
                    <button type="button" x-on:click="edit('income', @js(str_replace('.', ',', Money::toInput($incomeActual, $currency))), @js(__('Actual income')))"
                            @class(['press h-[30px] rounded-full px-3 text-[13px] font-semibold', 'bg-hero-2 text-hero-ink' => $leftover >= 0, 'bg-danger/16 text-ink' => $leftover < 0]) data-test="income-actual">{{ __('Was it different?') }}</button>
                </div>
                @if ($leftover > 0 && $unspent > 0)
                    <div class="num mt-1.5 text-[13px] text-hero-sub" data-test="close-unspent">{{ __(':amount of it is what the budgets did not use up', ['amount' => money_number($unspent)]) }}</div>
                @endif
            </section>

            @if ($preview->pocketDeposits !== [])
                <div class="num mt-3 rounded-[20px] bg-surface px-4 py-1.5" data-test="close-deposits">
                    <div class="flex h-10 items-center text-[13px] text-muted">{{ __('The monthly saving in the plan also moves') }}</div>
                    @foreach ($preview->pocketDeposits as $deposit)
                        <div class="flex h-11 items-center justify-between border-t border-line text-[15px]" wire:key="deposit-{{ $deposit['pocket_id'] }}">
                            <span class="truncate pr-3 text-ink-2">{{ $deposit['name'] }}</span>
                            <span class="font-semibold">+{{ money_number($deposit['amount']) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($leftover > 0)
                <h2 class="mx-1 mt-4 mb-2 text-[17px] font-semibold">{{ __('Where should it go?') }}</h2>
                <div class="flex flex-col gap-1.5" role="radiogroup" aria-label="{{ __('Where should it go?') }}" data-test="leftover-options">
                    @foreach ($options as $key => [$title, $idle, $picked])
                        @php $on = $chosen === $key; @endphp
                        <button type="button" role="radio" aria-checked="{{ $on ? 'true' : 'false' }}" wire:click="choose('{{ $key }}')" wire:key="option-{{ $key }}"
                                @class(['press flex min-h-[60px] items-center gap-3.5 rounded-[20px] border-[1.5px] px-4 py-2 text-left', 'border-accent bg-picked' => $on, 'border-surface bg-surface' => ! $on]) data-test="option-{{ $key }}">
                            <span @class(['flex size-[22px] shrink-0 items-center justify-center rounded-full border-2', 'border-accent' => $on, 'border-surface-3' => ! $on])><span @class(['size-2.5 rounded-full', 'bg-accent' => $on])></span></span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-base font-semibold">{{ $title }}</span>
                                <span @class(['num mt-0.5 block text-[13px]', 'text-accent' => $on, 'text-muted' => ! $on])>{{ $on ? $picked : $idle }}</span>
                            </span>
                        </button>
                    @endforeach
                </div>

                @if ($chosen === 'split')
                    <div class="num mt-2 rounded-[20px] bg-surface px-4 py-1.5" data-test="split">
                        <div class="flex h-[52px] items-center justify-between">
                            <span class="text-[15px] text-ink-2">{{ __('To the reserve') }}</span>
                            <button type="button" x-on:click="edit('reserve', @js(str_replace('.', ',', Money::toInput($allocation->toReserve, $currency))), @js(__('To the reserve')))" class="h-10 min-w-[120px] rounded-xl border-[1.5px] border-accent bg-surface-2 px-3 text-right text-[17px] font-semibold" data-test="edit-reserve">{{ money_number($allocation->toReserve) }}</button>
                        </div>
                        <div class="flex h-[52px] items-center justify-between border-t border-line">
                            <span class="truncate pr-3 text-[15px] text-ink-2">{{ $splitOther }}</span>
                            <button type="button" x-on:click="edit('rest', @js(str_replace('.', ',', Money::toInput($allocation->toSurplus, $currency))), @js($splitOther))" class="h-10 min-w-[120px] rounded-xl bg-surface-2 px-3 text-right text-[17px] font-semibold" data-test="edit-rest">{{ money_number($allocation->toSurplus) }}</button>
                        </div>
                    </div>
                @endif
            @elseif ($leftover < 0)
                <div class="mt-3 rounded-[20px] bg-surface px-4 py-3.5 text-[15px] text-ink-2" data-test="close-deficit">{{ __('There is nothing to split this time. Nothing moves; you settle the gap in your bank.') }}</div>
            @endif
        </div>
    @else
        @php $record = $this->closeRecord; @endphp
        <div class="flex flex-1 flex-col px-6 pt-16">
            <x-ui.icon-tile icon="task_alt" tone="accent" :size="60" />
            <h1 class="mt-5 text-[30px] font-semibold leading-tight tracking-[-0.03em]">{{ __(':month is closed', ['month' => $month]) }}</h1>
            @if ($record)
                <div class="num mt-6 rounded-[22px] bg-surface px-[18px] py-1">
                    <div class="flex justify-between border-b border-line py-[13px] text-[15px]"><span class="text-ink-2">{{ __('Leftover') }}</span><span class="font-semibold">{{ money($record->leftover) }}</span></div>
                    <div class="flex justify-between border-b border-line py-[13px] text-[15px]"><span class="text-ink-2">{{ __('To the reserve') }}</span><span>{{ money($record->to_reserve) }}</span></div>
                    <div class="flex justify-between py-[13px] text-[15px]"><span class="text-ink-2">{{ __('Rest') }}</span><span>{{ money($record->to_invest) }}</span></div>
                </div>
                @foreach (($record->breakdown['prepay_ready'] ?? []) as $ready)
                    @if ($ready['loan_id'] && ! in_array($ready['pocket_id'], $prepaid, true))
                        <div wire:key="prepay-{{ $ready['pocket_id'] }}" class="mt-3 rounded-[22px] border border-accent/22 bg-accent/10 px-[18px] py-4">
                            <div class="text-[15px] font-semibold">{{ __('Prepayment ready') }}</div>
                            <div class="mt-1 text-[13px] text-ink-2">{{ __(':pocket reached :step. Record the prepayment to lower the next installment.', ['pocket' => $ready['name'], 'step' => money($ready['step'])]) }}</div>
                            <x-ui.button size="md" class="mt-3 w-full" wire:click="prepay({{ $ready['pocket_id'] }})" data-test="prepay-{{ $ready['pocket_id'] }}">{{ __('Record prepayment') }}</x-ui.button>
                        </div>
                    @endif
                @endforeach
                <livewire:reopen-closing :period-id="$period->id" :key="'reopen-'.$period->id" />
            @endif
        </div>
    @endif

    </div>

    <x-ui.action-bar>
        @if ($step <= 3)
            <x-ui.button class="flex-1" wire:click="close" wire:loading.attr="disabled" :disabled="! $this->canClose" data-test="close-confirm">{{ __('Close it') }}</x-ui.button>
        @else
            <x-ui.button class="flex-1" :href="route('dashboard')" wire:navigate>{{ __('To the new period') }}</x-ui.button>
        @endif
    </x-ui.action-bar>

    {{-- Amount editor: actual income and the two sides of the leftover split --}}
    <div x-show="editorOpen" x-cloak x-trap.inert="editorOpen" class="fixed inset-0 z-50" role="dialog" aria-modal="true">
        <div x-show="editorOpen" x-transition.opacity class="absolute inset-0 bg-black/55" x-on:click="editorOpen = false"></div>
        <div class="absolute inset-x-0 bottom-0 mx-auto max-w-lg rounded-t-[30px] bg-surface px-4 pb-[calc(1.25rem+env(safe-area-inset-bottom))] pt-2" x-show="editorOpen" x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0" x-transition:leave="transition duration-200 ease-in" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full">
            <div class="mx-auto h-[5px] w-9 rounded-full bg-ink/18"></div>
            <div class="mt-1 grid h-11 grid-cols-[72px_1fr_72px] items-center">
                <button type="button" class="text-left text-[15px] text-muted" x-on:click="editorOpen = false">{{ __('Cancel') }}</button>
                <div class="truncate text-center text-base font-semibold" x-text="editorTitle"></div><span></span>
            </div>
            <div class="num py-4 text-center text-[52px] font-semibold tracking-[-0.04em]" x-text="display()"></div>
            <div class="mb-2 text-center text-xs text-danger" x-show="editorError" x-text="editorError"></div>
            <x-ui.numpad :decimal="$currency->decimals() > 0" />
            <x-ui.button x-on:click="save()" class="mt-3 w-full" data-test="editor-save">{{ __('Save') }}</x-ui.button>
        </div>
    </div>
</div>
</div>

@script
<script>
    Alpine.data('closeWizard', ({ decimals, locale, symbol }) => ({
        decimals,
        editorOpen: false,
        editorError: null,
        editorTitle: '',
        field: 'income',
        value: '',
        formatter: new Intl.NumberFormat(locale, { maximumFractionDigits: 0, useGrouping: 'always' }),

        press(key) {
            let value = String(this.value ?? '')
            if (key === 'del') value = value.slice(0, -1)
            else if (key === ',') { if (this.decimals > 0 && ! value.includes(',')) value = (value || '0') + ',' }
            else if (key === '000') { if (value && ! value.includes(',')) value += '000' }
            else { if (value === '0') value = ''; value += key }
            this.value = value.slice(0, 12)
        },
        display() {
            const [whole, fraction] = String(this.value || '0').split(',')
            return this.formatter.format(parseInt(whole || '0', 10)) + (fraction !== undefined ? ',' + fraction : '') + ' ' + symbol
        },
        edit(field, value, title) { this.field = field; this.value = value; this.editorTitle = title; this.editorError = null; this.editorOpen = true },
        async save() {
            const value = this.value || '0'
            const result = this.field === 'income' ? await $wire.setIncome(value) : await $wire.setSplit(this.field, value)
            this.editorError = result.error
            if (result.ok) this.editorOpen = false
        },
    }))
</script>
@endscript
