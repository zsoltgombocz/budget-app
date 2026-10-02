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

    public function goTo(int $step): void
    {
        if ($this->period->isOpen()) {
            $this->step = max(1, min(3, $step));
            unset($this->preview);
        }
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
        $pocket = $user->pockets()->findOrFail($pocketId);
        $loan = $pocket->loan_id !== null ? $user->loans()->find($pocket->loan_id) : null;

        abort_if($loan === null || $pocket->prepay_step === null, 404);

        $recordPrepayment->handle($user, $loan, min($pocket->prepay_step, $pocket->balance), $pocket);
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
        $settings = $this->user()->settings();

        return app(PeriodService::class)->boundsFor($settings->period_mode, $settings->payday_day, $this->period->ends_on->addDay());
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

    #[Computed]
    public function reserveBalance(): int
    {
        return (int) ($this->user()->pockets()->where('is_reserve', true)->value('balance') ?? 0);
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
    $month = Dates::monthName($period->starts_on);
    $snap = $currency->decimals() > 0 ? 100 : 1000;
@endphp

<div>
<div class="flex min-h-[calc(100dvh-var(--safe-top)-env(safe-area-inset-bottom))] flex-col pb-[calc(10rem+env(safe-area-inset-bottom))]"
     wire:key="wizard-{{ $step }}-{{ $incomeActual }}"
     x-data="closeWizard({
        leftover: {{ $preview ? max(0, $preview->leftover()) : 0 }},
        split: {{ $preview?->allocation->toReserve ?? 0 }},
        reserveBalance: {{ $this->reserveBalance }},
        snap: {{ $snap }},
        decimals: {{ $currency->decimals() }},
        locale: @js(str_replace('_', '-', app()->getLocale())),
        symbol: @js($currency->symbol()),
     })">
    @if ($step <= 3)
        <div class="grid grid-cols-[44px_1fr_44px] items-center px-3 pt-1.5">
            @if ($step === 1)
                <a href="{{ route('month', ['periodus' => $period->id]) }}" wire:navigate class="flex size-11 items-center justify-center text-ink-2" aria-label="{{ __('Close') }}"><x-ui.icon name="close" /></a>
            @else
                <button type="button" wire:click="goTo({{ $step - 1 }})" class="flex size-11 items-center justify-center text-ink-2" aria-label="{{ __('Back') }}"><x-ui.icon name="arrow_back" /></button>
            @endif
            <div class="text-center text-[15px] font-semibold">{{ __('Close the month') }}</div>
            <div class="text-center font-mono text-[13px] text-muted">{{ $step }}/3</div>
        </div>
        <x-ui.steps :current="$step" />
    @endif

    @if ($step === 1)
        <div class="px-6 pt-[22px]">
            <h1 class="text-[28px] font-semibold tracking-[-0.03em]">{{ __('Plan vs. actual') }}</h1>
            <div class="mt-1 text-sm text-muted">{{ $month }} · {{ Dates::range($period->starts_on, $period->ends_on) }}</div>
        </div>

        @if ($this->isEarly)
            <div class="mx-4 mt-4 flex gap-2 rounded-[18px] bg-warn/12 px-4 py-3 text-[13px] text-ink-2"><x-ui.icon name="info" :size="18" class="text-warn" />{{ __('The period has not ended yet. You can still close it early.') }}</div>
        @endif

        @php
            $plannedLeftover = $preview->incomeActual - $preview->plannedTotal;
            $variableRows = array_values(array_filter($preview->categories, fn ($row) => $row['type'] === 'variable'));
            $fixedCount = count($preview->categories) - count($variableRows);
            $variableDiff = array_sum(array_column($variableRows, 'diff'));
        @endphp

        <div class="mx-4 mb-3 mt-[18px] grid grid-cols-2 gap-3 rounded-[22px] bg-surface px-[18px] py-4">
            <div><div class="text-xs text-muted">{{ __('Planned leftover') }}</div><div class="num mt-1 text-xl font-semibold text-ink-2">{{ money($plannedLeftover) }}</div></div>
            <div><div class="text-xs text-muted">{{ __('Actual leftover') }}</div><div @class(['num mt-1 text-xl font-semibold', 'text-accent' => $preview->leftover() >= 0, 'text-danger' => $preview->leftover() < 0])>{{ money($preview->leftover()) }}</div></div>
        </div>

        <div class="mx-4 rounded-[22px] bg-surface px-2 py-1.5" data-test="plan-vs-actual">
            <div class="grid grid-cols-[minmax(0,1fr)_64px_64px_70px] gap-1.5 px-2.5 pb-1.5 pt-2.5 text-[11px] font-semibold uppercase tracking-[0.05em] text-muted">
                <span>{{ __('Category') }}</span><span class="text-right">{{ __('Plan') }}</span><span class="text-right">{{ __('Actual') }}</span><span class="text-right">{{ __('Diff.') }}</span>
            </div>
            @foreach ($variableRows as $row)
                <div wire:key="row-{{ $row['category_id'] }}" @class([
                    'num grid grid-cols-[minmax(0,1fr)_64px_64px_70px] items-center gap-1.5 rounded-[14px] px-2.5 py-[11px] text-sm',
                    'bg-warn/12' => $row['diff'] > 0,
                    'bg-accent/8' => $row['diff'] < 0 && $row['deviates'],
                ])>
                    <span class="truncate">{{ $row['name'] }}</span>
                    <span class="text-right text-muted">{{ money_number($row['planned']) }}</span>
                    <span class="text-right">{{ money_number($row['actual']) }}</span>
                    <span @class(['text-right font-semibold', 'text-warn' => $row['diff'] > 0, 'text-accent' => $row['diff'] < 0, 'text-muted' => $row['diff'] === 0])>{{ $row['diff'] > 0 ? '+' : '' }}{{ $row['diff'] === 0 ? '0' : money_number($row['diff']) }}</span>
                </div>
            @endforeach
            <div class="num grid grid-cols-[minmax(0,1fr)_70px] items-center gap-1.5 border-t border-line px-2.5 py-3 text-sm">
                <span class="flex items-center gap-1.5">{{ trans_choice('{1} Fixed items · :count|[2,*] Fixed items · :count', $fixedCount, ['count' => $fixedCount]) }}<x-ui.icon name="check" :size="16" :weight="600" class="text-accent" /></span>
                <span class="text-right text-muted">0</span>
            </div>
            <div class="num grid grid-cols-[minmax(0,1fr)_70px] items-center gap-1.5 border-t border-line px-2.5 py-3 text-sm font-semibold">
                <span>{{ __('Total') }}</span>
                <span @class(['text-right', 'text-warn' => $variableDiff > 0, 'text-accent' => $variableDiff < 0])>{{ $variableDiff > 0 ? '+' : '' }}{{ money_number($variableDiff) }}</span>
            </div>
        </div>
    @elseif ($step === 2)
        @php
            $allocation = $preview->allocation;
            $hasReserve = $preview->reservePocketId !== null;
            $target = $preview->surplusTarget['name'] ?? __('Stays on the account');
        @endphp
        <div class="px-6 pt-[22px]">
            <h1 class="text-[28px] font-semibold tracking-[-0.03em]">{{ $preview->leftover() >= 0 ? __('Split the leftover') : __('Cover the deficit') }}</h1>
            <div class="mt-1 text-sm text-muted">{{ $preview->leftover() >= 0 ? __('Where should the :month leftover go?', ['month' => Dates::monthInSentence($period->starts_on, true)]) : __('The deficit is taken from the reserve, never from an overdraft.') }}</div>
        </div>
        <x-ui.amount :value="$preview->leftover()" size="xl" :tone="$preview->leftover() >= 0 ? 'accent' : 'danger'" class="px-6 pt-[26px]" />

        @if ($preview->leftover() >= 0)
            <div class="mx-4 mt-6 grid grid-cols-2 gap-3">
                <div class="rounded-[22px] bg-surface p-4">
                    <div class="flex items-center gap-2 text-[13px] text-ink-2"><span class="size-2.5 rounded-[3px] bg-accent"></span>{{ __('To the reserve') }}</div>
                    <div class="num mt-2 text-2xl font-semibold" x-text="format(split)"></div>
                    <div class="num mt-0.5 text-xs text-muted">@if ($hasReserve){{ __('new balance') }} <span x-text="format(reserveBalance + split)"></span>@else{{ __('no reserve pocket') }}@endif</div>
                </div>
                <div class="rounded-[22px] bg-surface p-4">
                    <div class="flex items-center gap-2 text-[13px] text-ink-2"><span class="size-2.5 rounded-[3px] bg-ink-2"></span><span class="truncate">{{ $target }}</span></div>
                    <div class="num mt-2 text-2xl font-semibold" x-text="format(leftover - split)"></div>
                    <div class="mt-0.5 text-xs text-muted">{{ __('manual transfer') }}</div>
                </div>
            </div>

            @php
                $current = match ($preview->surplusTarget['type']) {
                    'account', 'pocket' => $preview->surplusTarget['type'].':'.$preview->surplusTarget['id'],
                    'new-pocket' => 'new-pocket',
                    default => 'none',
                };
            @endphp
            <div class="mx-4 mt-5">
                <x-ui.section-label :label="__('The rest goes to')" />
                <div class="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4" data-test="surplus-targets">
                    @foreach ($this->targets as $key => $label)
                        <x-ui.choice :selected="$current === $key" wire:click="chooseTarget('{{ $key }}')" class="h-10 shrink-0 whitespace-nowrap rounded-xl px-3 text-[13px]" wire:key="target-{{ $key }}">{{ $label }}</x-ui.choice>
                    @endforeach
                </div>
                @if ($surplusTarget !== null)
                    <label class="mt-3 flex items-center justify-between rounded-[14px] bg-surface px-4 py-3 text-sm">
                        <span>{{ __('Use it next time too') }}</span>
                        <x-ui.toggle :on="$saveTargetAsDefault" wire:click="$toggle('saveTargetAsDefault')" :aria-label="__('Use it next time too')" />
                    </label>
                @endif
            </div>

            @if ($hasReserve && $preview->leftover() > 0)
                <div class="relative mx-6 mt-7 h-9">
                    <div class="absolute inset-x-0 top-3.5 h-2 overflow-hidden rounded bg-ink-2"><div class="h-full bg-accent" :style="'width:' + pct() + '%'"></div></div>
                    <div class="pointer-events-none absolute top-1 -ml-3.5 size-7 rounded-full bg-ink shadow-[0_2px_10px_rgba(0,0,0,0.5),0_0_0_4px_var(--app-bg)]" :style="'left:' + pct() + '%'"></div>
                    <input type="range" min="0" :max="leftover" step="1" :value="split" x-on:input="slide($event.target.value)" x-on:change="commit()"
                           class="absolute inset-0 h-full w-full cursor-pointer opacity-0" aria-label="{{ __('To the reserve') }}" data-test="split-slider">
                </div>
                <div class="mx-4 mt-[18px] flex justify-center gap-2">
                    @foreach ([__('All to the reserve') => 'leftover', __('Half and half') => 'half', (in_array($preview->surplusTarget['type'], ['pocket', 'new-pocket'], true) ? __('All to the pocket') : ($preview->surplusTarget['type'] === 'account' ? __('All to invest') : __('All stays'))) => 'none'] as $label => $preset)
                        <button type="button" x-on:click="preset(@js($preset))" class="h-10 whitespace-nowrap rounded-xl border px-3 text-[13px] font-medium"
                                :class="isPreset(@js($preset)) ? 'border-accent bg-accent/14' : 'border-ink/8 bg-surface'">{{ $label }}</button>
                    @endforeach
                </div>
            @endif
        @else
            <div class="mx-4 mt-6 rounded-[22px] bg-surface px-[18px] py-1">
                <div class="num flex justify-between border-b border-line py-[13px] text-[15px]"><span class="text-ink-2">{{ __('Taken from the reserve') }}</span><span>{{ money(-$allocation->fromReserve) }}</span></div>
                <div @class(['num flex justify-between py-[13px] text-[15px]', 'text-danger' => $allocation->uncovered > 0])><span>{{ __('Not covered by the reserve') }}</span><span>{{ money(-$allocation->uncovered) }}</span></div>
            </div>
        @endif

        @if ($preview->pocketDeposits !== [])
            <div class="mx-4 mt-5">
                <x-ui.section-label :label="__('Monthly pocket savings')" />
                <div class="rounded-[22px] bg-surface px-[18px]">
                    @foreach ($preview->pocketDeposits as $deposit)
                        <div wire:key="deposit-{{ $deposit['pocket_id'] }}" @class(['num flex justify-between py-3 text-sm', 'border-b border-line' => ! $loop->last])><span>{{ $deposit['name'] }}</span><span>+{{ money($deposit['amount']) }}</span></div>
                    @endforeach
                </div>
            </div>
        @endif
    @elseif ($step === 3)
        @php
            $allocation = $preview->allocation;
            [$nextStart, $nextEnd] = $this->nextBounds;
        @endphp
        <div class="px-6 pt-[22px]">
            <h1 class="text-[28px] font-semibold tracking-[-0.03em]">{{ __('Summary') }}</h1>
            <div class="mt-1 text-sm text-muted">{{ __('One more look before closing :month.', ['month' => Dates::monthInSentence($period->starts_on)]) }}</div>
        </div>

        <div class="num mx-4 mb-3 mt-[18px] rounded-[22px] bg-surface px-[18px] py-1">
            <button type="button" x-on:click="editIncome(@js(str_replace('.', ',', Money::toInput($incomeActual, $currency))))" class="flex w-full items-center justify-between border-b border-line py-[13px] text-[15px]" data-test="income-actual">
                <span class="text-ink-2">{{ __('Income') }}</span>
                <span class="flex items-center gap-1.5">{{ money($preview->incomeActual) }}<x-ui.icon name="edit" :size="16" class="text-faint" /></span>
            </button>
            <div class="flex justify-between border-b border-line py-[13px] text-[15px]"><span class="text-ink-2">{{ __('Expenses') }}</span><span>{{ money($preview->actualTotal) }}</span></div>
            <div class="flex justify-between py-[13px] text-[15px] font-semibold"><span>{{ __('Leftover') }}</span><span @class(['text-accent' => $preview->leftover() >= 0, 'text-danger' => $preview->leftover() < 0])>{{ money($preview->leftover()) }}</span></div>
        </div>

        <div class="num mx-4 mb-3 rounded-[22px] bg-surface px-[18px] py-1">
            @if ($preview->leftover() >= 0)
                <div class="grid grid-cols-[32px_1fr_auto] items-center gap-2.5 border-b border-line py-[13px]">
                    <x-ui.icon name="shield" :size="22" class="text-accent" />
                    <div><div class="text-[15px]">{{ __('To the reserve') }}</div>@if ($preview->reservePocketId)<div class="mt-0.5 text-xs text-muted">{{ __('new balance :amount', ['amount' => money($this->reserveBalance + $allocation->toReserve)]) }}</div>@endif</div>
                    <span class="text-[15px] font-semibold">+{{ money($allocation->toReserve) }}</span>
                </div>
                <div class="grid grid-cols-[32px_1fr_auto] items-center gap-2.5 py-[13px]">
                    <x-ui.icon name="show_chart" :size="22" class="text-ink-2" />
                    <div><div class="text-[15px]">{{ $preview->surplusTarget['name'] ?? __('Stays on the account') }}</div><div class="mt-0.5 text-xs text-muted">{{ __('we remind you to transfer it') }}</div></div>
                    <span class="text-[15px] font-semibold">+{{ money($allocation->toSurplus) }}</span>
                </div>
            @else
                <div class="grid grid-cols-[32px_1fr_auto] items-center gap-2.5 py-[13px]">
                    <x-ui.icon name="shield" :size="22" class="text-danger" />
                    <div class="text-[15px]">{{ __('Taken from the reserve') }}</div>
                    <span class="text-[15px] font-semibold">{{ money(-$allocation->fromReserve) }}</span>
                </div>
            @endif
        </div>

        <div class="mx-4 rounded-[22px] border border-dashed border-ink/14 px-[18px] py-4">
            <div class="text-[15px] font-semibold">{{ Dates::monthName($nextStart) }} · {{ Dates::range($nextStart, $nextEnd) }}</div>
            <div class="mt-1.5 text-[13px] leading-normal text-pretty text-muted">{{ $this->nextPeriodNote }}</div>
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
            @endif
        </div>
    @endif

    <div class="fixed inset-x-0 bottom-0 z-20 mx-auto flex max-w-lg gap-2.5 bg-gradient-to-t from-bg via-bg to-transparent px-4 pb-[calc(1.5rem+env(safe-area-inset-bottom))] pt-6">
        @if ($step === 1)
            <x-ui.button class="flex-1" wire:click="goTo(2)" data-test="close-next">{{ __('Next') }}</x-ui.button>
        @elseif ($step === 2)
            <x-ui.button variant="secondary" class="w-[110px]" wire:click="goTo(1)">{{ __('Back') }}</x-ui.button>
            <x-ui.button class="flex-1" wire:click="goTo(3)" data-test="close-next">{{ __('Next') }}</x-ui.button>
        @elseif ($step === 3)
            <x-ui.button variant="secondary" class="w-[110px]" wire:click="goTo(2)">{{ __('Back') }}</x-ui.button>
            <x-ui.button class="flex-1" wire:click="close" wire:loading.attr="disabled" data-test="close-confirm">{{ __('Open the new month') }}</x-ui.button>
        @else
            <x-ui.button class="flex-1" :href="route('dashboard')" wire:navigate>{{ __('To the new period') }}</x-ui.button>
        @endif
    </div>

    {{-- Income editor --}}
    <div x-show="incomeOpen" x-cloak class="fixed inset-0 z-50" role="dialog" aria-modal="true">
        <div x-show="incomeOpen" x-transition.opacity class="absolute inset-0 bg-black/55" x-on:click="incomeOpen = false"></div>
        <div class="absolute inset-x-0 bottom-0 mx-auto max-w-lg rounded-t-[30px] bg-surface px-4 pb-[calc(1.25rem+env(safe-area-inset-bottom))] pt-2" x-show="incomeOpen" x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0" x-transition:leave="transition duration-200 ease-in" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full">
            <div class="mx-auto h-[5px] w-9 rounded-full bg-ink/18"></div>
            <div class="mt-1 grid h-11 grid-cols-[72px_1fr_72px] items-center">
                <button type="button" class="text-left text-[15px] text-muted" x-on:click="incomeOpen = false">{{ __('Cancel') }}</button>
                <div class="text-center text-base font-semibold">{{ __('Actual income') }}</div><span></span>
            </div>
            <div class="num py-4 text-center text-[52px] font-semibold tracking-[-0.04em]" x-text="display('income')"></div>
            <div class="mb-2 text-center text-xs text-danger" x-show="incomeError" x-text="incomeError"></div>
            <x-ui.numpad :decimal="$currency->decimals() > 0" />
            <x-ui.button x-on:click="saveIncome()" class="mt-3 w-full">{{ __('Save') }}</x-ui.button>
        </div>
    </div>
</div>
</div>

@script
<script>
    Alpine.data('closeWizard', ({ leftover, split, reserveBalance, snap, decimals, locale, symbol }) => ({
        leftover, split, reserveBalance, snap, decimals,
        incomeOpen: false,
        incomeError: null,
        fields: {},
        active: 'income',
        formatter: new Intl.NumberFormat(locale, { maximumFractionDigits: 0, useGrouping: 'always' }),

        format(minor) { return this.formatter.format(minor / Math.pow(10, this.decimals)) + ' ' + symbol },
        pct() { return this.leftover > 0 ? this.split / this.leftover * 100 : 0 },
        slide(raw) {
            const value = parseInt(raw, 10)
            this.split = value > this.leftover - this.snap / 2 ? this.leftover : (value < this.snap / 2 ? 0 : Math.round(value / this.snap) * this.snap)
        },
        commit() { $wire.split(this.split) },
        presetValue(name) { return { leftover: this.leftover, half: Math.ceil(this.leftover / 2), none: 0 }[name] },
        preset(name) { this.split = this.presetValue(name); this.commit() },
        isPreset(name) { return this.split === this.presetValue(name) },

        press(key) {
            let value = String(this.fields.income ?? '')
            if (key === 'del') value = value.slice(0, -1)
            else if (key === ',') { if (this.decimals > 0 && ! value.includes(',')) value = (value || '0') + ',' }
            else if (key === '000') { if (value && ! value.includes(',')) value += '000' }
            else { if (value === '0') value = ''; value += key }
            this.fields.income = value.slice(0, 12)
        },
        display() {
            const [whole, fraction] = String(this.fields.income || '0').split(',')
            return this.formatter.format(parseInt(whole || '0', 10)) + (fraction !== undefined ? ',' + fraction : '')
        },
        editIncome(value) { this.fields = { income: value }; this.incomeError = null; this.incomeOpen = true },
        async saveIncome() {
            const result = await $wire.setIncome(this.fields.income || '0')
            this.incomeError = result.error
            if (result.ok) this.incomeOpen = false
        },
    }))
</script>
@endscript
