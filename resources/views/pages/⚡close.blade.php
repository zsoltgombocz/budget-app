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

    /** The quick split last picked ("all to the reserve" 100, "half and half" 50, "all to the target" 0); null after a split typed by hand. */
    public ?int $splitPreset = null;

    /** Keep the picked quick split as the leftover rule (reserve share) for the next closings. */
    public bool $saveSplitAsRule = false;

    /** Take a deficit from the reserve pocket; the user decides on the split step. */
    public bool $coverDeficit = true;

    /** @var list<int> pockets whose prepayment was recorded after closing */
    public array $prepaid = [];

    public function mount(int $period): void
    {
        $model = $this->user()->periods()->findOrFail($period);

        if ($model->isOpen() && ! app(PeriodCloser::class)->canClose($this->user(), $model)) {
            $this->redirectRoute('month', ['periodus' => $model->id], navigate: true);
        }

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
        $this->splitPreset = null;
        $this->saveSplitAsRule = false;
        unset($this->preview);
    }

    /**
     * One of the quick splits: the share of the leftover that goes to the reserve.
     */
    public function applySplitPreset(int $pct): void
    {
        if (! in_array($pct, [0, 50, 100], true)) {
            return;
        }

        $leftover = max(0, $this->preview->leftover());
        $this->split((int) ceil($leftover * $pct / 100));
        $this->splitPreset = $pct;
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
        $closer->close($user, $this->period, $this->incomeActual, $this->toReserve, $this->surplusTarget, $this->coverDeficit);

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

        if ($this->saveSplitAsRule && $this->splitPreset !== null) {
            $user->settings()->update(['reserve_pct' => $this->splitPreset, 'reserve_fixed' => null]);
        }

        // No toast: the summary that follows says it, and a toast would cover its button.
        $this->step = 4;
        unset($this->period, $this->preview, $this->closeRecord);
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
        return app(PeriodCloser::class)->preview($this->user(), $this->period, $this->incomeActual, $this->toReserve, $this->surplusTarget, $this->coverDeficit);
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

    /**
     * The leftover rule for the reserve in one sentence, and how far the reserve is from its
     * target: why the reserve box suggests what it does.
     */
    #[Computed]
    public function reserveRuleNote(): ?string
    {
        $preview = $this->preview;
        $settings = $this->user()->settings();

        if ($preview->reservePocketId === null || $preview->leftover() <= 0) {
            return null;
        }

        $rule = $settings->reserve_fixed !== null
            ? __('Your rule: at most :amount of the leftover goes to the reserve.', ['amount' => money($settings->reserve_fixed)])
            : __('Your rule: :pct% of the leftover goes to the reserve.', ['pct' => $settings->reserve_pct]);

        if ($preview->reserveTarget === null) {
            return $rule.' '.__('The reserve has no target, so there is no upper limit.');
        }

        return $rule.' '.($preview->reserveRoom > 0
            ? __('It stops at the target (:target): :room is still missing.', ['target' => money($preview->reserveTarget), 'room' => money((int) $preview->reserveRoom)])
            : __('The reserve has reached its target (:target), so the rule gives it nothing more.', ['target' => money($preview->reserveTarget)]));
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
    // What happens with the rest of the leftover, by target, in plain words.
    $targetLine = fn (?string $type, ?string $name): array => match ($type) {
        'account' => [$name ?? '', __('Transfer it in your bank; we remind you until you mark it done.')],
        'pocket' => [$name ?? '', __('The app adds it to this pocket’s balance. If you keep the pocket on a separate account, transfer it there too.')],
        'new-pocket' => [$name ?? __('Savings'), __('The app opens this pocket for it.')],
        default => [__('Stays on the account'), __('Nothing to do: the money stays on your account.')],
    };
@endphp

<div>
<div class="flex min-h-[calc(100dvh-var(--safe-top)-env(safe-area-inset-bottom))] flex-col pb-[calc(10rem+env(safe-area-inset-bottom))]"
     x-data="closeWizard({
        decimals: {{ $currency->decimals() }},
        locale: @js(str_replace('_', '-', app()->getLocale())),
     })">
    @if ($step <= 3)
        <div class="grid grid-cols-[44px_1fr_44px] items-center px-3 pt-4">
            @if ($step === 1)
                <a href="{{ route('month', ['periodus' => $period->id]) }}" wire:navigate class="focus-ring flex size-11 items-center justify-center rounded-xl text-ink-2" aria-label="{{ __('Close') }}"><x-ui.icon name="close" /></a>
            @else
                <button type="button" wire:click="goTo({{ $step - 1 }})" class="focus-ring flex size-11 items-center justify-center rounded-xl text-ink-2" aria-label="{{ __('Back') }}"><x-ui.icon name="arrow_back" /></button>
            @endif
            <div class="text-center text-[15px] font-semibold">{{ __('Close the month') }}</div>
            <div class="text-center font-mono text-[13px] text-muted" aria-hidden="true">{{ $step }}/3</div>
        </div>
        <x-ui.steps :current="$step" />
    @endif

    <div wire:key="step-{{ $step }}" class="step-enter">

    @if ($step === 1)
        <div class="px-6 pt-[22px]">
            <h1 class="text-[28px] font-semibold tracking-[-0.03em]">{{ __('Plan vs. actual') }}</h1>
            <div class="mt-1 text-sm text-muted">{{ $month }} · {{ Dates::range($period->starts_on, $period->ends_on) }}</div>
        </div>

        @if ($this->isEarly)
            <x-ui.callout tone="warn" class="mx-4 mt-4" data-test="close-early">{{ __('The period has not ended yet. If you close it now, today is its last day and the new month starts today: what you record after closing goes there.') }}</x-ui.callout>
        @endif

        @php
            // Variable categories and skipped fixed items get a row each; the other fixed items go as planned.
            $variableRows = array_values(array_filter($preview->categories, fn ($row) => $row['type'] === 'variable' || $row['skipped']));
            $fixedRows = array_values(array_filter($preview->categories, fn ($row) => $row['type'] !== 'variable' && ! $row['skipped']));
            $fixedCount = count($fixedRows);
            $fixedTotal = array_sum(array_column($fixedRows, 'actual'));
            $plannedSum = array_sum(array_column($variableRows, 'planned'));
            $actualSum = array_sum(array_column($variableRows, 'actual'));
            // One sign everywhere: + is money left in the budget, − is overspending.
            $remaining = $plannedSum - $actualSum;
            $tableRows = array_map(fn (array $row): array => [
                'name' => $row['name'],
                'planned' => $row['planned'],
                'actual' => $row['actual'],
                'note' => $row['skipped'] ? __('skipped') : ($row['actual'] > $row['planned'] ? __('overspent') : null),
                'noteTone' => $row['skipped'] ? 'muted' : 'danger',
                'key' => $row['category_id'],
                'test' => 'close-row',
            ], $variableRows);
        @endphp

        <div class="mx-4 mb-3 mt-[18px] grid grid-cols-1 gap-3 rounded-card bg-surface px-[18px] py-4 min-[340px]:grid-cols-2">
            <div><div class="text-xs text-muted">{{ __('Planned leftover') }}</div><x-ui.amount :value="$preview->plannedLeftover()" class="mt-1 text-ink-2" /></div>
            <div><div class="text-xs text-muted">{{ __('Actual leftover') }}</div><x-ui.amount :value="$preview->leftover()" :tone="$preview->leftover() >= 0 ? 'accent' : 'danger'" class="mt-1" /></div>
        </div>

        <x-ui.plan-actual-table :rows="$tableRows" :caption="__('Plan vs. actual')" :labels="['deviation' => __('Left')]"
                                :total="['name' => __('Budgets in total'), 'planned' => $plannedSum, 'actual' => $actualSum, 'test' => 'close-total']"
                                class="mx-4" data-test="plan-vs-actual">
            @if ($fixedCount > 0)
                <x-slot:after>
                    <div role="row" class="col-span-full flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 border-t border-line px-2.5 py-3 text-sm" data-test="fixed-summary">
                        <span role="rowheader" class="flex items-center gap-1.5 text-[15px]">{{ trans_choice('{1} Fixed items · :count|[2,*] Fixed items · :count', $fixedCount, ['count' => $fixedCount]) }}<x-ui.icon name="check" :size="16" :weight="600" class="text-accent" /></span>
                        <span role="cell" class="num ml-auto text-muted">{{ __(':amount, the planned amount', ['amount' => money($fixedTotal)]) }}</span>
                    </div>
                </x-slot:after>
            @endif
        </x-ui.plan-actual-table>
        <p @class(['mx-4 mt-2 px-2 text-[13px] leading-snug', 'text-danger' => $remaining < 0, 'text-ink-2' => $remaining >= 0]) data-test="close-verdict">
            {{ $remaining < 0 ? __(':amount over the budget', ['amount' => money(-$remaining)]) : __(':amount under the budget', ['amount' => money($remaining)]) }}
        </p>
    @elseif ($step === 2)
        @php
            $allocation = $preview->allocation;
            $hasReserve = $preview->reservePocketId !== null;
            $target = $preview->surplusTarget['name'] ?? __('Stays on the account');
            $targetNote = match ($preview->surplusTarget['type']) {
                'account' => __('manual transfer'),
                'pocket' => __('the app puts it into this pocket'),
                'new-pocket' => __('the app opens a new pocket for it'),
                default => __('nothing moves'),
            };
        @endphp
        <div class="px-6 pt-[22px]">
            <h1 class="text-[28px] font-semibold tracking-[-0.03em]">{{ $preview->leftover() >= 0 ? __('Split the leftover') : __('Cover the deficit') }}</h1>
            <div class="mt-1 text-sm text-muted">{{ $preview->leftover() >= 0 ? __('Where should the :month leftover go?', ['month' => Dates::monthInSentence($period->nameDate(), true)]) : __('You choose whether the deficit is taken from the reserve.') }}</div>
        </div>
        <x-ui.callout icon="account_balance" class="mx-4 mt-4" data-test="bank-note">{{ __('The app only keeps records: you make the real transfers in your bank.') }}</x-ui.callout>
        {{-- Seven-digit amounts get the smaller size so they fit a 320 px screen. --}}
        <x-ui.amount :value="$preview->leftover()" :size="abs($preview->leftover()) >= 1_000_000 ? 'lg' : 'xl'" :tone="$preview->leftover() >= 0 ? 'accent' : 'danger'" class="max-w-full px-6 pt-6" />

        @if ($preview->leftover() >= 0)
            @php
                $leftover = $preview->leftover();
                $current = match ($preview->surplusTarget['type']) {
                    'account', 'pocket' => $preview->surplusTarget['type'].':'.$preview->surplusTarget['id'],
                    'new-pocket' => 'new-pocket',
                    default => 'none',
                };
                $canSplit = $hasReserve && $leftover > 0;
                $overTarget = $preview->reserveRoom !== null && $allocation->toReserve > $preview->reserveRoom;
            @endphp
            <div class="mx-4 mt-6 grid grid-cols-1 gap-3 min-[340px]:grid-cols-2" data-test="split">
                <button type="button" @if ($canSplit) x-on:click="edit('reserve', @js(str_replace('.', ',', Money::toInput($allocation->toReserve, $currency))), @js(__('To the reserve')))" @endif
                        @class(['focus-ring min-w-0 rounded-card bg-surface p-4 text-left', 'cursor-default' => ! $canSplit]) data-test="edit-reserve" @disabled(! $canSplit)>
                    <span class="flex items-start gap-2 text-[13px] text-ink-2"><span class="mt-[5px] size-2.5 shrink-0 rounded-[3px] bg-accent"></span><span class="min-w-0 flex-1 leading-snug break-words">{{ __('To the reserve') }}</span>@if ($canSplit)<x-ui.icon name="edit" :size="16" class="shrink-0 text-faint" />@endif</span>
                    <x-ui.amount size="sm" :value="$allocation->toReserve" class="mt-2 max-w-full text-2xl font-semibold" />
                    <span class="num mt-0.5 block text-xs text-muted">@if ($hasReserve){{ __('new balance') }} {{ money($preview->reserveBalanceAfter ?? 0) }}@else{{ __('no reserve pocket') }}@endif</span>
                </button>
                <button type="button" @if ($canSplit) x-on:click="edit('rest', @js(str_replace('.', ',', Money::toInput($allocation->toSurplus, $currency))), @js($target))" @endif
                        @class(['focus-ring min-w-0 rounded-card bg-surface p-4 text-left', 'cursor-default' => ! $canSplit]) data-test="edit-rest" @disabled(! $canSplit)>
                    <span class="flex items-start gap-2 text-[13px] text-ink-2"><span class="mt-[5px] size-2.5 shrink-0 rounded-[3px] bg-ink-2"></span><span class="min-w-0 flex-1 leading-snug break-words">{{ $target }}</span>@if ($canSplit)<x-ui.icon name="edit" :size="16" class="shrink-0 text-faint" />@endif</span>
                    <x-ui.amount size="sm" :value="$allocation->toSurplus" class="mt-2 max-w-full text-2xl font-semibold" />
                    <span class="mt-0.5 block text-xs text-muted" data-test="rest-note">{{ $targetNote }}</span>
                </button>
            </div>
            @if ($hasReserve && $allocation->toReserve > 0)
                <p class="mx-4 mt-2 px-2 text-xs leading-snug text-muted" data-test="reserve-transfer-note">{{ __('The reserve pocket is a record in the app. If you keep the reserve on a separate account, transfer this much there.') }}</p>
            @endif
            @if ($this->reserveRuleNote && $allocation->toReserve === $preview->ruleToReserve)
                <p class="mx-4 mt-2 px-2 text-xs leading-snug text-muted" data-test="reserve-rule-note">{{ $this->reserveRuleNote }}</p>
            @endif
            @if ($overTarget)
                <p class="mx-4 mt-2 px-2 text-xs leading-snug text-ink-2" data-test="over-target-note">{{ __('That is more than the reserve needs to reach its target (:room missing). Fine: the extra goes into the reserve too.', ['room' => money((int) $preview->reserveRoom)]) }}</p>
            @endif

            @if ($canSplit)
                @php
                    $restLabel = in_array($preview->surplusTarget['type'], ['pocket', 'new-pocket'], true) ? __('All to the pocket') : ($preview->surplusTarget['type'] === 'account' ? __('All to invest') : __('All stays'));
                    $presets = [[__('All to the reserve'), 100], [__('Half and half'), 50], [$restLabel, 0]];
                    $settings = auth()->user()->settings();
                    $ruleDiffers = $splitPreset !== null && ($settings->reserve_fixed !== null || $settings->reserve_pct !== $splitPreset);
                @endphp
                {{-- Three quick splits as one row of chips; the "keep it as my rule" switch sits under the row. --}}
                <div class="mx-4 mt-3 grid grid-cols-3 gap-2" role="group" aria-label="{{ __('Quick split') }}" data-test="split-presets">
                    @foreach ($presets as [$label, $pct])
                        <x-ui.choice :selected="$allocation->toReserve === (int) ceil($leftover * $pct / 100)" wire:click="applySplitPreset({{ $pct }})" class="rounded-control px-2 py-1.5 text-[13px] leading-tight text-balance" wire:key="preset-{{ $pct }}" data-test="preset-{{ $pct }}">{{ $label }}</x-ui.choice>
                    @endforeach
                </div>
                @if ($ruleDiffers)
                    <label class="mx-4 mt-2 flex items-center justify-between gap-3 rounded-control bg-surface px-4 py-3 text-sm" data-test="save-split">
                        <span>{{ __('Next time too: :pct% of the leftover to the reserve (up to its target)', ['pct' => $splitPreset]) }}</span>
                        <x-ui.toggle :on="$saveSplitAsRule" wire:click="$toggle('saveSplitAsRule')" :aria-label="__('Next time too: :pct% of the leftover to the reserve (up to its target)', ['pct' => $splitPreset])" />
                    </label>
                @endif
            @endif

            <div class="mx-4 mt-5">
                <x-ui.section-label :label="__('The rest goes to')" />
                <div class="grid gap-2" data-test="surplus-targets">
                    @foreach ($this->targets as $key => $label)
                        <x-ui.choice :selected="$current === $key" wire:click="chooseTarget('{{ $key }}')" class="rounded-btn px-4 py-3 text-left text-[15px] font-semibold" wire:key="target-{{ $key }}">{{ $label }}</x-ui.choice>
                        {{-- Right under the choice it is about. --}}
                        @if ($current === $key && $current !== $this->defaultTarget)
                            <label class="flex items-center justify-between gap-3 rounded-[14px] bg-surface px-4 py-3 text-sm" data-test="save-target" wire:key="save-target-{{ $key }}">
                                <span>{{ __('Use it next time too') }}</span>
                                <x-ui.toggle :on="$saveTargetAsDefault" wire:click="$toggle('saveTargetAsDefault')" :aria-label="__('Use it next time too')" />
                            </label>
                        @endif
                    @endforeach
                </div>
            </div>
        @else
            @if ($hasReserve)
                <div class="mx-4 mt-6">
                    <div class="px-1.5 pb-2 text-[13px] text-muted">{{ __('You spent more than came in. Do you take the gap from the reserve?') }}</div>
                    <div class="grid gap-2" data-test="cover-deficit">
                        <x-ui.choice :selected="$coverDeficit" wire:click="$set('coverDeficit', true)" class="rounded-btn px-4 py-3 text-left" data-test="cover-yes">
                            <span class="block text-[15px] font-semibold">{{ __('Yes, take it from the reserve') }}</span>
                            <span class="mt-1 block text-[13px] font-normal leading-snug text-muted">{{ __('The reserve pocket goes down by the gap, as far as its balance allows.') }}</span>
                        </x-ui.choice>
                        <x-ui.choice :selected="! $coverDeficit" wire:click="$set('coverDeficit', false)" class="rounded-btn px-4 py-3 text-left" data-test="cover-no">
                            <span class="block text-[15px] font-semibold">{{ __('No, leave the reserve alone') }}</span>
                            <span class="mt-1 block text-[13px] font-normal leading-snug text-muted">{{ __('Nothing moves. The gap is only recorded in the closing.') }}</span>
                        </x-ui.choice>
                    </div>
                </div>
            @endif
            <div class="mx-4 mt-4 rounded-card bg-surface px-[18px] py-1">
                <div class="num flex justify-between gap-3 border-b border-line py-[13px] text-[15px]"><span class="text-ink-2">{{ __('Taken from the reserve') }}</span><span>{{ money(-$allocation->fromReserve) }}</span></div>
                <div @class(['num flex justify-between gap-3 py-[13px] text-[15px]', 'text-danger' => $allocation->uncovered > 0])><span>{{ __('Not covered') }}</span><span>{{ money(-$allocation->uncovered) }}</span></div>
            </div>
        @endif

        @if ($preview->pocketDeposits !== [])
            <div class="mx-4 mt-5">
                <x-ui.section-label :label="__('Sinking funds')" />
                <div class="-mt-1 mb-2 px-1.5 text-xs text-pretty text-muted">{{ __('The monthly amounts the Plan puts into pockets. Closing adds them to the pockets’ balances.') }}</div>
                <div class="rounded-card bg-surface px-[18px]">
                    @foreach ($preview->pocketDeposits as $deposit)
                        <div wire:key="deposit-{{ $deposit['pocket_id'] }}" @class(['num flex justify-between gap-3 py-3 text-sm', 'border-b border-line' => ! $loop->last])><span class="min-w-0 break-words">{{ $deposit['name'] }}</span><span class="shrink-0">+{{ money($deposit['amount']) }}</span></div>
                    @endforeach
                </div>
            </div>
        @endif
    @elseif ($step === 3)
        @php
            $allocation = $preview->allocation;
            [$nextStart, $nextEnd] = $this->nextBounds;
            $targetNote = match ($preview->surplusTarget['type']) {
                'account' => __('we remind you to transfer it'),
                'pocket' => __('the app puts it into this pocket'),
                'new-pocket' => __('the app opens a new pocket for it'),
                default => __('nothing moves'),
            };
        @endphp
        <div class="px-6 pt-[22px]">
            <h1 class="text-[28px] font-semibold tracking-[-0.03em]">{{ __('Summary') }}</h1>
            <div class="mt-1 text-sm text-muted">{{ __('One more look before closing :month.', ['month' => Dates::monthInSentence($period->nameDate())]) }}</div>
        </div>

        <div class="num mx-4 mb-3 mt-[18px] rounded-card bg-surface px-[18px] py-1">
            <button type="button" x-on:click="edit('income', @js(str_replace('.', ',', Money::toInput($incomeActual, $currency))), @js(__('Actual income')), @js(__('Only counts in the :month closing, your plan does not change.', ['month' => Dates::monthInSentence($period->nameDate(), true)])))" class="focus-ring flex w-full items-center justify-between gap-3 border-b border-line py-[13px] text-[15px]" data-test="income-actual">
                <span class="text-ink-2">{{ __('Income') }}</span>
                <span class="flex items-center gap-1.5"><x-ui.amount size="sm" :value="$preview->incomeActual" /><x-ui.icon name="edit" :size="16" class="text-faint" /></span>
            </button>
            <div class="flex justify-between gap-3 border-b border-line py-[13px] text-[15px]"><span class="text-ink-2">{{ __('Expenses') }}</span><x-ui.amount size="sm" :value="$preview->actualTotal" /></div>
            <div class="flex justify-between gap-3 py-[13px] text-[15px] font-semibold"><span>{{ __('Leftover') }}</span><x-ui.amount size="sm" :value="$preview->leftover()" :tone="$preview->leftover() >= 0 ? 'accent' : 'danger'" /></div>
        </div>

        <div class="num mx-4 mb-3 rounded-card bg-surface px-[18px] py-1">
            @if ($preview->leftover() >= 0)
                <div class="grid grid-cols-[28px_minmax(0,1fr)_auto] items-center gap-2.5 border-b border-line py-[13px]">
                    <x-ui.icon name="shield" :size="22" class="text-accent" />
                    <div><div class="text-[15px]">{{ __('To the reserve') }}</div>@if ($preview->reservePocketId)<div class="mt-0.5 text-xs text-muted">{{ __('new balance :amount', ['amount' => money($preview->reserveBalanceAfter ?? 0)]) }}</div>@endif</div>
                    <x-ui.amount size="sm" :value="$allocation->toReserve" signed class="text-[15px] font-semibold" />
                </div>
                <div class="grid grid-cols-[28px_minmax(0,1fr)_auto] items-center gap-2.5 py-[13px]">
                    <x-ui.icon name="show_chart" :size="22" class="text-ink-2" />
                    <div class="min-w-0"><div class="break-words text-[15px]">{{ $preview->surplusTarget['name'] ?? __('Stays on the account') }}</div><div class="mt-0.5 text-xs text-muted" data-test="summary-rest-note">{{ $targetNote }}</div></div>
                    <x-ui.amount size="sm" :value="$allocation->toSurplus" signed class="text-[15px] font-semibold" />
                </div>
            @else
                <div class="grid grid-cols-[28px_minmax(0,1fr)_auto] items-center gap-2.5 py-[13px]">
                    <x-ui.icon name="shield" :size="22" class="text-danger" />
                    <div class="text-[15px]">{{ __('Taken from the reserve') }}</div>
                    <x-ui.amount size="sm" :value="-$allocation->fromReserve" class="text-[15px] font-semibold" />
                </div>
            @endif
        </div>

        @include('partials.close-pocket-moves', ['moves' => $preview->pocketMoves])

        <p class="mx-4 mb-3 flex gap-2 px-2 text-xs leading-snug text-muted"><x-ui.icon name="account_balance" :size="16" class="mt-px shrink-0" />{{ __('The app only keeps records: you make the real transfers in your bank.') }}</p>

        <x-ui.callout icon="event_note" :title="Dates::monthName(max($nextStart, $nextEnd->subDays(27))).' · '.Dates::range($nextStart, $nextEnd)" class="mx-4" data-test="next-period">{{ $this->nextPeriodNote }}</x-ui.callout>
    @else
        @php
            $record = $this->closeRecord;
            $breakdown = $record?->breakdown ?? [];
            [$restName, $restTodo] = $targetLine($breakdown['surplus_target']['type'] ?? null, $breakdown['surplus_target']['name'] ?? null);
        @endphp
        <div class="flex flex-1 flex-col px-6 pt-16">
            <x-ui.icon-tile icon="task_alt" tone="accent" :size="60" />
            <h1 class="mt-5 text-[30px] font-semibold leading-tight tracking-[-0.03em]">{{ __(':month is closed', ['month' => $month]) }}</h1>
            <p class="mt-1.5 text-sm text-muted">{{ __('A new month has started.') }}</p>
            @if ($record)
                <div class="num mt-6 rounded-card bg-surface px-[18px] py-1" data-test="closed-summary">
                    <div class="flex justify-between gap-3 border-b border-line py-[13px] text-[15px]"><span class="text-ink-2">{{ __('Leftover') }}</span><span @class(['font-semibold', 'text-danger' => $record->leftover < 0])>{{ money($record->leftover) }}</span></div>
                    @if ($record->leftover >= 0)
                        <div class="flex justify-between gap-3 border-b border-line py-[13px] text-[15px]"><span class="text-ink-2">{{ __('To the reserve') }}</span><span>{{ $record->to_reserve > 0 ? '+' : '' }}{{ money($record->to_reserve) }}</span></div>
                        <div class="py-[13px] text-[15px]" data-test="closed-rest">
                            <div class="flex justify-between gap-3"><span class="min-w-0 break-words text-ink-2">{{ $restName }}</span><span>{{ $record->to_invest > 0 ? '+' : '' }}{{ money($record->to_invest) }}</span></div>
                            <div class="mt-0.5 text-xs leading-snug text-muted">{{ $restTodo }}</div>
                        </div>
                    @else
                        <div class="flex justify-between gap-3 border-b border-line py-[13px] text-[15px]"><span class="text-ink-2">{{ __('Taken from the reserve') }}</span><span>{{ money(-$record->from_reserve) }}</span></div>
                        <div class="flex justify-between gap-3 py-[13px] text-[15px]"><span class="text-ink-2">{{ __('Not covered') }}</span><span>{{ money(-(int) ($breakdown['allocation']['uncovered'] ?? 0)) }}</span></div>
                    @endif
                </div>
                @include('partials.close-pocket-moves', ['moves' => $breakdown['pocket_moves'] ?? [], 'class' => 'mt-4'])
                @foreach (($breakdown['prepay_ready'] ?? []) as $ready)
                    @if ($ready['loan_id'] && ! in_array($ready['pocket_id'], $prepaid, true))
                        <x-ui.callout tone="success" :title="__('Prepayment ready')" wire:key="prepay-{{ $ready['pocket_id'] }}" class="mt-3">
                            {{ __(':pocket reached :step. Record the prepayment to lower the next installment.', ['pocket' => $ready['name'], 'step' => money($ready['step'])]) }}
                            <x-slot:actions>
                                <x-ui.button variant="tonal" size="md" icon="payments" wire:click="prepay({{ $ready['pocket_id'] }})" data-test="prepay-{{ $ready['pocket_id'] }}">{{ __('Record prepayment') }}</x-ui.button>
                            </x-slot:actions>
                        </x-ui.callout>
                    @endif
                @endforeach
                <livewire:reopen-closing :period-id="$period->id" variant="text" :key="'reopen-'.$period->id" />
            @endif
        </div>
    @endif

    </div>

    <x-ui.action-bar>
        @if ($step === 1)
            <x-ui.button class="flex-1" wire:click="goTo(2)" data-test="close-next">{{ __('Next') }}</x-ui.button>
        @elseif ($step === 2)
            <x-ui.button variant="secondary" class="basis-1/3" wire:click="goTo(1)">{{ __('Back') }}</x-ui.button>
            <x-ui.button class="flex-1" wire:click="goTo(3)" data-test="close-next">{{ __('Next') }}</x-ui.button>
        @elseif ($step === 3)
            <x-ui.button variant="secondary" class="basis-1/3" wire:click="goTo(2)">{{ __('Back') }}</x-ui.button>
            <x-ui.button class="min-w-0 flex-1" wire:click="close" wire:loading.attr="disabled" data-test="close-confirm">{{ __('Open the new month') }}</x-ui.button>
        @else
            <x-ui.button class="flex-1" :href="route('dashboard')" wire:navigate data-test="to-new-month">{{ __('To the new month') }}</x-ui.button>
        @endif
    </x-ui.action-bar>

    {{-- Amount editor: actual income and the two sides of the leftover split --}}
    <x-ui.numpad-sheet show="editorOpen" close="editorOpen = false" title="editorTitle" done="save()" display="display('value')" :decimal="$currency->decimals() > 0" error="editorError" intro="editorIntro" data-test="amount-editor" />
</div>
</div>

@script
<script>
    Alpine.data('closeWizard', ({ decimals, locale }) => ({
        ...window.amountFields({ decimals, locale, fields: { value: '' }, active: 'value' }),
        editorOpen: false,
        editorError: null,
        editorTitle: '',
        editorIntro: '',
        field: 'income',

        edit(field, value, title, intro = '') {
            this.field = field
            this.editAmount('value', value, title)
            this.editorTitle = title
            this.editorIntro = intro
            this.editorError = null
            this.editorOpen = true
        },
        async save() {
            const value = this.fields.value || '0'
            const result = this.field === 'income' ? await $wire.setIncome(value) : await $wire.setSplit(this.field, value)
            this.editorError = result.error
            if (result.ok) this.editorOpen = false
        },
    }))
</script>
@endscript
