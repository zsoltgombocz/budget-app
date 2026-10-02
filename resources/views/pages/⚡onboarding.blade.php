<?php

use App\Actions\Budget\CompleteOnboarding;
use App\Enums\Currency;
use App\Enums\LineType;
use App\Enums\PeriodMode;
use App\Models\CategoryTemplate;
use App\Models\User;
use App\Support\Icons;
use App\Support\Money;
use App\Support\OnboardingItems;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Setup wizard. Instead of picking a preset it walks through every part of a plan and asks
 * about each one (shared costs? a loan?), so any combination comes out of one pass. Every
 * line can be switched off, and the whole wizard can be skipped.
 */
new #[Title('Set up your budget')] #[Layout('layouts::app', ['tabs' => false])] class extends Component {
    public const int STEPS = 8;

    /**
     * Wizard step => the group of plan lines it asks about.
     */
    public const array GROUP_STEPS = [4 => 'fixed', 5 => 'shared', 6 => 'loan', 7 => 'daily', 8 => 'reserve'];

    public int $step = 1;

    public string $income = '';

    public string $periodMode = 'payday';

    public ?int $paydayDay = 10;

    public string $currency = 'HUF';

    /** Shares costs through a joint account (step 5); null until answered. */
    public ?bool $shared = null;

    /** Has a loan (step 6); null until answered. */
    public ?bool $hasLoan = null;

    /** @var array<int, string> */
    public array $amounts = [];

    /** @var array<int, bool> */
    public array $included = [];

    /** Optional loan details on the loan step; the installment is the loan line's amount. */
    public string $loanPrincipal = '';

    public string $loanThm = '';

    public string $loanMonths = '';

    public string $reserveTarget = '';

    /** Share of the month-end leftover that goes to the reserve on top of the monthly amount. */
    public int $reservePct = 0;

    public string $surplusTarget = 'investment';

    public function mount(): void
    {
        if ($this->user()->settings()->isOnboarded()) {
            $this->redirectRoute('dashboard', navigate: true);

            return;
        }

        $items = OnboardingItems::all();
        $this->amounts = array_fill(0, count($items), '');
        $this->included = array_map(fn (array $entry): bool => ! ($entry['item']['off'] ?? false), $items);
    }

    public function next(): void
    {
        $this->validateStep();

        $this->step = min(self::STEPS, $this->step + 1);
    }

    public function back(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function finish(CompleteOnboarding $completeOnboarding): void
    {
        foreach (range(1, self::STEPS) as $step) {
            $this->step = $step;
            $this->validateStep();
        }

        $currency = Currency::from($this->currency);

        $this->complete($completeOnboarding, $currency, includedIndexes: $this->includedIndexes());
    }

    /**
     * Start with an empty plan: everything is set up later on the Plan screen and in Settings.
     */
    public function skip(CompleteOnboarding $completeOnboarding): void
    {
        $currency = Currency::tryFrom($this->currency) ?? Currency::HUF;

        if ($this->income !== '' && Money::parse($this->income, $currency) === null) {
            $this->income = '';
        }

        $this->complete($completeOnboarding, $currency, includedIndexes: []);
    }

    /**
     * @return array<int, array{group: string, item: array<string, mixed>}>
     */
    #[Computed]
    public function items(): array
    {
        return OnboardingItems::all();
    }

    /**
     * Indexes of the lines on a wizard step.
     *
     * @return list<int>
     */
    public function indexesFor(string $group): array
    {
        return array_keys(array_filter($this->items, fn (array $entry): bool => $entry['group'] === $group));
    }

    #[Computed]
    public function currencyEnum(): Currency
    {
        return Currency::tryFrom($this->currency) ?? Currency::HUF;
    }

    /**
     * What the plan entered so far leaves at month end and where that leftover would go,
     * shown on the last step so the reserve settings are easy to follow.
     *
     * @return array{income: int, planned: int, reserveMonthly: int, leftover: int, toReserve: int, toSurplus: int, reserveOn: bool}
     */
    #[Computed]
    public function leftoverPreview(): array
    {
        $currency = $this->currencyEnum;
        $income = Money::parse($this->income === '' ? '0' : $this->income, $currency) ?? 0;
        $reserveOn = $this->reserveIsOn();
        $planned = 0;
        $reserveMonthly = 0;

        foreach ($this->includedIndexes() as $index) {
            $amount = $this->amounts[$index] ?? '';
            $value = $amount === '' ? 0 : (Money::parse($amount, $currency) ?? 0);

            if ($index === $this->reserveIndex()) {
                $reserveMonthly = $value;
            } else {
                $planned += $value;
            }
        }

        $leftover = $income - $planned - $reserveMonthly;
        $target = $this->reserveTarget === '' ? null : Money::parse($this->reserveTarget, $currency);
        $toReserve = 0;

        if ($reserveOn && $leftover > 0) {
            $toReserve = intdiv($leftover * $this->reservePct, 100);
            $toReserve = $target === null ? $toReserve : min($toReserve, $target);
        }

        return [
            'income' => $income,
            'planned' => $planned,
            'reserveMonthly' => $reserveMonthly,
            'leftover' => $leftover,
            'toReserve' => $toReserve,
            'toSurplus' => max(0, $leftover - $toReserve),
            'reserveOn' => $reserveOn,
        ];
    }

    public function reserveIndex(): int
    {
        return $this->indexesFor('reserve')[0];
    }

    private function reserveIsOn(): bool
    {
        return $this->included[$this->reserveIndex()] ?? true;
    }

    /**
     * @param  list<int>  $includedIndexes
     */
    private function complete(CompleteOnboarding $completeOnboarding, Currency $currency, array $includedIndexes): void
    {
        $plan = new CategoryTemplate(['key' => 'wizard', 'name' => 'Wizard', 'items' => array_column($this->items, 'item')]);
        $withReserve = in_array($this->reserveIndex(), $includedIndexes, true);
        $withLoan = $this->hasLoan === true && in_array($this->indexesFor('loan')[0], $includedIndexes, true);

        $completeOnboarding->handle(
            user: $this->user(),
            income: (int) Money::parse($this->income === '' ? '0' : $this->income, $currency),
            periodMode: PeriodMode::tryFrom($this->periodMode) ?? PeriodMode::Calendar,
            paydayDay: $this->paydayDay,
            currency: $currency,
            template: $plan,
            amounts: array_map(fn (string $amount): int => $amount === '' ? 0 : (int) Money::parse($amount, $currency), $this->amounts),
            // Without a reserve pocket there is no target and no share of the leftover for it.
            reserveTarget: ! $withReserve || $this->reserveTarget === '' ? null : Money::parse($this->reserveTarget, $currency),
            reservePct: $this->reservePct,
            surplusTarget: $this->surplusTarget === 'pocket' ? 'pocket' : 'investment',
            included: $includedIndexes,
            loanDetails: $withLoan ? [
                'principal' => $this->loanPrincipal === '' ? null : Money::parse($this->loanPrincipal, $currency),
                'thm' => $this->loanThm === '' ? null : (float) str_replace(',', '.', $this->loanThm),
                'months' => $this->loanMonths === '' ? null : (int) $this->loanMonths,
            ] : [],
        );

        $this->redirectRoute('notifications.onboarding', navigate: true);
    }

    /**
     * Lines that are switched on, leaving out the groups answered with "no".
     *
     * @return list<int>
     */
    private function includedIndexes(): array
    {
        $skipped = array_keys(array_filter(['shared' => $this->shared === false, 'loan' => $this->hasLoan === false]));

        return array_values(array_filter(
            array_keys($this->items),
            fn (int $index): bool => ($this->included[$index] ?? true) && ! in_array($this->items[$index]['group'], $skipped, true),
        ));
    }

    private function validateStep(): void
    {
        $currency = $this->currencyEnum;
        $money = function (string $attribute, mixed $value, Closure $fail) use ($currency): void {
            if ($value !== '' && $value !== null && (! is_scalar($value) || Money::parse((string) $value, $currency) === null)) {
                $fail(__('Enter a valid amount.'));
            }
        };

        $amountRules = [];

        foreach ($this->indexesFor(self::GROUP_STEPS[$this->step] ?? '') as $index) {
            $amountRules["amounts.{$index}"] = ['nullable', $money];
        }

        $rules = match ($this->step) {
            1 => ['income' => ['required', $money]],
            2 => [
                'periodMode' => ['required', Rule::enum(PeriodMode::class)],
                'paydayDay' => [Rule::requiredIf($this->periodMode === PeriodMode::Payday->value), 'nullable', 'integer', 'between:1,31'],
            ],
            3 => ['currency' => ['required', Rule::enum(Currency::class)]],
            5 => ['shared' => ['required', 'boolean'], ...$amountRules],
            6 => [
                'hasLoan' => ['required', 'boolean'],
                ...$amountRules,
                'loanPrincipal' => ['nullable', $money],
                'loanThm' => ['nullable', 'regex:/^\d{1,2}([.,]\d{1,3})?$/'],
                'loanMonths' => ['nullable', 'integer', 'between:1,600'],
            ],
            8 => [
                ...$amountRules,
                'reserveTarget' => ['nullable', $money],
                'reservePct' => ['required', 'integer', 'between:0,100'],
                'surplusTarget' => ['required', Rule::in(['investment', 'pocket'])],
            ],
            default => $amountRules,
        };

        $this->validate($rules, [
            'shared.required' => __('Choose yes or no.'),
            'hasLoan.required' => __('Choose yes or no.'),
            'loanThm.regex' => __('Enter the APR as a percentage, e.g. 7,9.'),
        ]);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>


@php
    $currency = $this->currencyEnum;
    $titles = [
        1 => [__('How much do you earn?'), __('Your monthly net income. The plan starts from this.')],
        2 => [__('When does your month start?'), __('Plan from payday to payday, or by calendar month.')],
        3 => [__('Which currency?'), __('Every amount in the plan is in this currency.')],
        4 => [__('Housing and monthly fees'), __('What you pay from your own account every month. Switch off what you do not have, leave empty what you do not know yet.')],
        5 => [__('Do you share costs with someone?'), __('For example a joint account with your partner that you both transfer to every month.')],
        6 => [__('Do you have a loan?'), __('Its monthly installment is a fixed line in the plan, you do not record it as spending. The principal and the APR can come later.')],
        7 => [__('Everyday spending'), __('Monthly budgets for what you record day by day. Rough numbers are fine, you can change them any time.')],
        8 => [__('Reserve and leftover'), __('Two choices: do you want a reserve, and what happens to the money left at month end.')],
    ];
    $question = match ($step) {
        5 => ['model' => 'shared', 'value' => $shared, 'yes' => __('Yes, we have a joint account'), 'no' => __('No, I pay everything myself')],
        6 => ['model' => 'hasLoan', 'value' => $hasLoan, 'yes' => __('Yes, I repay a loan'), 'no' => __('No loan')],
        default => null,
    };
    $group = $this::GROUP_STEPS[$step] ?? null;
    $showItems = $group !== null && $group !== 'reserve' && ($question === null || $question['value'] === true);
@endphp

<div class="flex min-h-[calc(100dvh-var(--safe-top)-env(safe-area-inset-bottom))] flex-col pb-[calc(10rem+env(safe-area-inset-bottom))]"
     x-data="{
        field: null,
        fieldLabel: '',
        value: '',
        decimals: {{ $currency->decimals() }},
        formatter: new Intl.NumberFormat(@js(str_replace('_', '-', app()->getLocale())), { maximumFractionDigits: 0, useGrouping: 'always' }),
        fieldDecimals: {{ $currency->decimals() }},
        fieldSuffix: @js($currency->symbol()),
        open(name, current, label = '', decimals = null, suffix = null) {
            this.field = name; this.fieldLabel = label; this.value = String(current ?? '').replace('.', ',')
            this.fieldDecimals = decimals ?? this.decimals
            this.fieldSuffix = suffix ?? @js($currency->symbol())
        },
        press(key) {
            let v = this.value
            if (key === 'del') v = v.slice(0, -1)
            else if (key === ',') { if (this.fieldDecimals > 0 && ! v.includes(',')) v = (v || '0') + ',' }
            else if (key === '000') { if (v && ! v.includes(',')) v += '000' }
            else { const f = v.split(',')[1]; if (f !== undefined && f.length >= this.fieldDecimals) return; if (v === '0') v = ''; v += key }
            this.value = v.slice(0, 12)
        },
        show(raw, fallback = '0') {
            const v = String(raw ?? '')
            if (v === '') return fallback
            const [w, f] = v.replace('.', ',').split(',')
            return this.formatter.format(parseInt(w || '0', 10)) + (f !== undefined ? ',' + f : '')
        },
        apply() { $wire.set(this.field, this.value); this.field = null },
     }">
    <div class="grid grid-cols-[44px_1fr_44px] items-center px-3 pt-3">
        @if ($step > 1)
            <button type="button" wire:click="back" class="flex size-11 items-center justify-center text-ink-2" aria-label="{{ __('Back') }}"><x-ui.icon name="arrow_back" /></button>
        @else
            <span></span>
        @endif
        <div class="text-center text-[15px] font-semibold">{{ __('Set up your budget') }}</div>
        <div class="text-center font-mono text-[13px] text-muted">{{ $step }}/{{ $this::STEPS }}</div>
    </div>
    <x-ui.steps :current="$step" :total="$this::STEPS" />

    <div wire:key="step-{{ $step }}" class="step-enter">
    <div class="px-6 pt-[22px]">
        <h1 class="text-[28px] font-semibold leading-tight tracking-[-0.03em] text-pretty">{{ $titles[$step][0] }}</h1>
        <p class="mt-1.5 text-sm leading-normal text-pretty text-muted">{{ $titles[$step][1] }}</p>
    </div>

    <div class="px-4 pt-6">
        @if ($step === 1)
            <button type="button" x-on:click="open('income', $wire.income, @js(__('Monthly net income')))" class="w-full rounded-card bg-surface px-5 py-6 text-left" data-test="onboarding-income">
                <span class="block text-[13px] text-muted">{{ __('Monthly net income') }}</span>
                <span class="num mt-1 flex items-baseline gap-2"><span class="text-[44px] font-semibold tracking-[-0.04em]" :class="$wire.income === '' && 'text-faint'" x-text="show($wire.income)"></span><span class="text-xl text-muted">{{ $currency->symbol() }}</span></span>
            </button>
            @error('income')<p class="mt-2 px-2 text-xs text-danger">{{ $message }}</p>@enderror
            <div class="mt-6 rounded-card border border-dashed border-line px-5 py-4">
                <div class="text-[15px] font-medium">{{ __('Rather build the plan yourself?') }}</div>
                <p class="mt-1 text-[13px] leading-snug text-muted">{{ __('Skip the questions and start with an empty plan. You add the lines on the Plan screen, the rest is in Settings.') }}</p>
                <x-ui.button variant="secondary" size="md" wire:click="skip" class="mt-3 w-full" data-test="onboarding-skip">{{ __('Skip, I set it up myself') }}</x-ui.button>
            </div>
        @elseif ($step === 2)
            <div class="grid gap-2">
                @foreach (PeriodMode::cases() as $mode)
                    <x-ui.choice :selected="$periodMode === $mode->value" wire:click="$set('periodMode', '{{ $mode->value }}')" class="rounded-btn px-4 py-4 text-left text-[15px]">
                        {{ $mode->label() }}
                    </x-ui.choice>
                @endforeach
            </div>
            @if ($periodMode === 'payday')
                <div class="mt-5 px-1 text-[13px] text-muted">{{ __('Payday (day of month)') }}</div>
                <div class="mt-2 grid grid-cols-7 gap-1.5" data-test="payday-grid">
                    @for ($day = 1; $day <= 31; $day++)
                        <x-ui.choice :selected="$paydayDay === $day" wire:click="$set('paydayDay', {{ $day }})" class="num aspect-square rounded-xl text-sm">{{ $day }}</x-ui.choice>
                    @endfor
                </div>
                <p class="mt-2 px-1 text-xs text-muted">{{ __('If the month is shorter, the last day counts.') }}</p>
            @endif
        @elseif ($step === 3)
            <div class="grid grid-cols-4 gap-2">
                @foreach (Currency::cases() as $option)
                    <x-ui.choice :selected="$currency === $option" wire:click="$set('currency', '{{ $option->value }}')" class="h-14 rounded-btn text-[15px]">{{ $option->value }}</x-ui.choice>
                @endforeach
            </div>
        @endif

        @if ($question !== null)
            <div class="grid gap-2" data-test="question-{{ $question['model'] }}">
                <x-ui.choice :selected="$question['value'] === true" wire:click="$set('{{ $question['model'] }}', true)" class="rounded-btn px-4 py-4 text-left text-[15px]" data-test="answer-yes">{{ $question['yes'] }}</x-ui.choice>
                <x-ui.choice :selected="$question['value'] === false" wire:click="$set('{{ $question['model'] }}', false)" class="rounded-btn px-4 py-4 text-left text-[15px]" data-test="answer-no">{{ $question['no'] }}</x-ui.choice>
            </div>
            @error($question['model'])<p class="mt-2 px-2 text-xs text-danger">{{ $message }}</p>@enderror
            @if ($showItems)
                <div class="mb-2.5 mt-6 px-1.5 text-xs font-semibold uppercase tracking-[0.06em] text-muted">{{ __('Monthly amounts') }}</div>
            @endif
        @endif

        @if ($showItems)
            <div class="flex flex-col gap-2.5">
                @foreach ($this->indexesFor($group) as $index)
                    @php
                        $item = $this->items[$index]['item'];
                        $on = $included[$index] ?? true;
                    @endphp
                    <div wire:key="item-{{ $index }}" class="rounded-[22px] bg-surface px-4 py-4" data-test="template-item">
                        <div class="flex items-start gap-3">
                            <x-ui.icon-tile :icon="Icons::forCategory($item['icon'] ?? null)" :size="40" class="{{ $on ? '' : 'opacity-40' }}" />
                            <div class="min-w-0 flex-1 pt-0.5 {{ $on ? '' : 'opacity-40' }}">
                                <div class="text-[15px] font-medium leading-snug">{{ __($item['name']) }}</div>
                                <div class="mt-0.5 text-xs text-muted">{{ LineType::from($item['type'])->label() }}</div>
                            </div>
                            <x-ui.toggle :on="$on" wire:click="$set('included.{{ $index }}', {{ $on ? 'false' : 'true' }})" :aria-label="__($item['name'])" class="mt-2" />
                        </div>

                        @if ($on)
                            @if (! empty($item['hint']))
                                <p class="mt-3 text-[13px] leading-relaxed text-muted">{{ __($item['hint']) }}</p>
                            @endif
                            <button type="button" x-on:click="open('amounts.{{ $index }}', $wire.amounts[{{ $index }}], @js(__($item['name'])))"
                                    class="mt-3 flex h-12 w-full items-center justify-between rounded-[14px] bg-surface-2 px-4 text-left" data-test="amount-{{ $index }}">
                                <span class="text-[13px] text-muted">{{ __('Monthly amount') }}</span>
                                <span class="num text-[17px] font-semibold">
                                    <span :class="! $wire.amounts[{{ $index }}] && 'text-faint'" x-text="show($wire.amounts[{{ $index }}], '0')"></span>
                                    <span class="text-sm font-medium text-muted">{{ $currency->symbol() }}</span>
                                </span>
                            </button>
                            @error('amounts.'.$index)<p class="mt-1.5 px-1 text-xs text-danger">{{ $message }}</p>@enderror
                            @if ($item['loan'] ?? false)
                                <div class="mt-4 border-t border-line pt-3" data-test="loan-details">
                                    <div class="text-[13px] font-medium">{{ __('Loan details (optional)') }}</div>
                                    <p class="mt-0.5 text-xs leading-snug text-muted">{{ __('With these the app shows the payoff and what a prepayment saves. You find them on your loan statement; you can add them later too: Plan, Loan repayments.') }}</p>
                                    @foreach ([
                                        ['field' => 'loanPrincipal', 'label' => __('Outstanding principal'), 'decimals' => null, 'suffix' => null],
                                        ['field' => 'loanThm', 'label' => __('APR (%)'), 'decimals' => 3, 'suffix' => '%'],
                                        ['field' => 'loanMonths', 'label' => __('Months left'), 'decimals' => 0, 'suffix' => __('months')],
                                    ] as $detail)
                                        <button type="button" x-on:click="open(@js($detail['field']), $wire.{{ $detail['field'] }}, @js($detail['label']), @js($detail['decimals']), @js($detail['suffix']))"
                                                class="mt-2 flex h-12 w-full items-center justify-between rounded-[14px] bg-surface-2 px-4 text-left" data-test="{{ $detail['field'] }}">
                                            <span class="text-[13px] text-muted">{{ $detail['label'] }}</span>
                                            <span class="num text-[17px] font-semibold">
                                                <span :class="! $wire.{{ $detail['field'] }} && 'text-faint'" x-text="show($wire.{{ $detail['field'] }}, '–')"></span>
                                                <span class="text-sm font-medium text-muted">{{ $detail['suffix'] ?? $currency->symbol() }}</span>
                                            </span>
                                        </button>
                                        @error($detail['field'])<p class="mt-1.5 px-1 text-xs text-danger">{{ $message }}</p>@enderror
                                    @endforeach
                                </div>
                            @endif
                        @endif
                    </div>
                @endforeach
            </div>
            @if (in_array($group, ['fixed', 'daily'], true))
                <p class="mt-3 flex gap-2 px-1.5 text-xs leading-snug text-muted" data-test="more-later">
                    <x-ui.icon name="add" :size="16" class="mt-px shrink-0 text-accent" />
                    <span>{{ $group === 'fixed'
                        ? __('Something missing? After the wizard you can add any number of fixed items on the Plan screen, e.g. each subscription on its own with its due day.')
                        : __('Something missing? After the wizard you can add more budgets on the Plan screen, e.g. for a pet or a hobby.') }}</span>
                </p>
            @endif
        @endif

        @if ($step === 8)
            @php
                $reserveIndex = $this->reserveIndex();
                $reserveOn = $included[$reserveIndex] ?? true;
                $preview = $this->leftoverPreview;
            @endphp
            <div class="rounded-[22px] bg-surface px-4 py-4" data-test="reserve-card">
                <div class="flex items-start gap-3">
                    <x-ui.icon-tile icon="shield" :size="40" class="{{ $reserveOn ? '' : 'opacity-40' }}" />
                    <div class="min-w-0 flex-1 pt-0.5">
                        <div class="text-[15px] font-medium leading-snug">{{ __('Reserve pocket') }}</div>
                        <div class="mt-0.5 text-xs text-muted">{{ __('A pocket for the unexpected') }}</div>
                    </div>
                    <x-ui.toggle :on="$reserveOn" wire:click="$set('included.{{ $reserveIndex }}', {{ $reserveOn ? 'false' : 'true' }})" :aria-label="__('Reserve pocket')" class="mt-2" data-test="reserve-toggle" />
                </div>
                @if ($reserveOn)
                    <p class="mt-3 text-[13px] leading-relaxed text-muted">{{ __('Money you put aside for the unexpected. If a month ends in the red, at closing you choose whether to take the gap from here.') }}</p>
                    @foreach ([
                        ['field' => 'amounts.'.$reserveIndex, 'value' => $amounts[$reserveIndex] ?? '', 'label' => __('Put aside every month'), 'hint' => __('Planned like a fixed cost and moved into the pocket at closing. 0 is fine too.'), 'fallback' => '0'],
                        ['field' => 'reserveTarget', 'value' => $reserveTarget, 'label' => __('Target'), 'hint' => __('Until the pocket reaches it, the month-end leftover also tops it up. Empty means no limit.'), 'fallback' => '–'],
                    ] as $row)
                        <button type="button" x-on:click="open(@js($row['field']), @js($row['value']), @js($row['label']))" class="mt-3 w-full rounded-[14px] bg-surface-2 px-4 py-3 text-left">
                            <span class="flex items-center justify-between">
                                <span class="text-[13px] text-muted">{{ $row['label'] }}</span>
                                <span class="num text-[17px] font-semibold"><span @class(['text-faint' => $row['value'] === ''])>{{ $row['value'] === '' ? $row['fallback'] : money_number(Money::parse($row['value'], $currency) ?? 0, $currency) }}</span> <span class="text-sm font-medium text-muted">{{ $currency->symbol() }}</span></span>
                            </span>
                            <span class="mt-1 block text-xs leading-snug text-muted">{{ $row['hint'] }}</span>
                        </button>
                    @endforeach
                    @error('amounts.'.$reserveIndex)<p class="mt-1.5 px-1 text-xs text-danger">{{ $message }}</p>@enderror
                    @error('reserveTarget')<p class="mt-1.5 px-1 text-xs text-danger">{{ $message }}</p>@enderror
                @else
                    <p class="mt-3 text-[13px] leading-relaxed text-muted">{{ __('No reserve pocket: the whole month-end leftover goes to the target below. You can add one later under Pockets.') }}</p>
                @endif
            </div>

            <div class="mb-2.5 mt-7 px-1.5 text-xs font-semibold uppercase tracking-[0.06em] text-muted">{{ __('If money is left at month end') }}</div>
            <div class="rounded-[22px] bg-surface px-4 py-4">
                @if ($reserveOn)
                    <div class="text-[13px] text-muted">{{ __('On top of the monthly amount, this share of the leftover also goes to the reserve until it reaches the target:') }}</div>
                    <div class="mt-2 grid grid-cols-4 gap-2">
                        @foreach ([0, 25, 50, 100] as $pct)
                            <x-ui.choice :selected="$reservePct === $pct" wire:click="$set('reservePct', {{ $pct }})" class="num h-11 rounded-xl text-[15px]">{{ $pct }}%</x-ui.choice>
                        @endforeach
                    </div>
                    <div class="mt-4 text-[13px] text-muted">{{ __('The rest goes to:') }}</div>
                @else
                    <div class="text-[13px] text-muted">{{ __('All of it goes to:') }}</div>
                @endif
                <div class="mt-2 grid gap-2" data-test="surplus-options">
                    <x-ui.choice :selected="$surplusTarget === 'investment'" wire:click="$set('surplusTarget', 'investment')" class="rounded-btn px-4 py-3 text-left">
                        <span class="block text-[15px] font-semibold">{{ __('Investment account') }}</span>
                        <span class="mt-1 block text-[13px] font-normal leading-snug text-muted">{{ __('At closing the rest is listed as a manual transfer and we remind you to move it to your broker or investment account. The app does not track its balance (yet).') }}</span>
                    </x-ui.choice>
                    <x-ui.choice :selected="$surplusTarget === 'pocket'" wire:click="$set('surplusTarget', 'pocket')" class="rounded-btn px-4 py-3 text-left">
                        <span class="block text-[15px] font-semibold">{{ __('Savings pocket') }}</span>
                        <span class="mt-1 block text-[13px] font-normal leading-snug text-muted">{{ __('At closing the rest is added to a “Savings” pocket in the app: you see its balance under Pockets and can take money out of it.') }}</span>
                    </x-ui.choice>
                </div>
            </div>

            <div class="mt-3 rounded-[22px] border border-accent/30 bg-accent/8 px-4 py-4" data-test="leftover-preview">
                <div class="flex items-center gap-2 text-[13px] font-semibold text-accent"><x-ui.icon name="info" :size="18" />{{ __('A month with your numbers') }}</div>
                <div class="num mt-2 text-[13px]">
                    <div class="flex justify-between py-1"><span class="text-muted">{{ __('Income') }}</span><span>{{ money($preview['income'], $currency) }}</span></div>
                    <div class="flex justify-between py-1"><span class="text-muted">{{ __('Planned costs and budgets') }}</span><span>−{{ money($preview['planned'], $currency) }}</span></div>
                    @if ($preview['reserveOn'])
                        <div class="flex justify-between py-1"><span class="text-muted">{{ __('Put aside in the reserve') }}</span><span>−{{ money($preview['reserveMonthly'], $currency) }}</span></div>
                    @endif
                    <div @class(['flex justify-between border-t border-accent/20 pt-1.5 mt-1 font-semibold', 'text-danger' => $preview['leftover'] < 0])><span>{{ __('Expected leftover') }}</span><span>{{ money($preview['leftover'], $currency) }}</span></div>
                </div>
                @if ($preview['leftover'] > 0)
                    <div class="mt-3 text-[13px] font-semibold">{{ __('Put aside every month') }}</div>
                    <div class="num mt-1 text-[13px]">
                        @if ($preview['reserveOn'])
                            <div class="flex justify-between py-1"><span class="text-muted">{{ __('Reserve: :fixed fixed + :share from the leftover', ['fixed' => money($preview['reserveMonthly'], $currency), 'share' => money($preview['toReserve'], $currency)]) }}</span><span class="font-semibold">{{ money($preview['reserveMonthly'] + $preview['toReserve'], $currency) }}</span></div>
                        @endif
                        <div class="flex justify-between py-1"><span class="text-muted">{{ $surplusTarget === 'pocket' ? __('Savings pocket') : __('Investment account') }}</span><span class="font-semibold">{{ money($preview['toSurplus'], $currency) }}</span></div>
                    </div>
                @elseif ($preview['leftover'] === 0)
                    <p class="mt-2 text-[13px] leading-relaxed text-ink-2">{{ __('Your plan uses up the whole income, so nothing is left to share out.') }}</p>
                @else
                    <p class="mt-2 text-[13px] leading-relaxed text-ink-2">{{ __('Your plan is :gap over the income a month.', ['gap' => money(-$preview['leftover'], $currency)]) }} {{ __('It is worth lowering a budget before you start.') }}</p>
                @endif
                <p class="mt-2 text-xs leading-snug text-muted">{{ __('The real numbers come at closing, from what you actually spent. You can change all of this later in Settings.') }}</p>
            </div>
        @endif
    </div>
    </div>

    <div class="fixed inset-x-0 bottom-0 z-20 mx-auto flex max-w-lg gap-2.5 bg-gradient-to-t from-bg via-bg to-transparent px-4 pb-[calc(1.5rem+env(safe-area-inset-bottom))] pt-6">
        @if ($step < $this::STEPS)
            <x-ui.button class="flex-1" wire:click="next" data-test="onboarding-next">{{ __('Next') }}</x-ui.button>
        @else
            <x-ui.button class="flex-1" wire:click="finish" data-test="onboarding-next">{{ __('Start budgeting') }}</x-ui.button>
        @endif
    </div>

    {{-- Numpad sheet for every amount on the wizard --}}
    <div x-show="field" x-cloak class="fixed inset-0 z-50" role="dialog" aria-modal="true">
        <div x-show="field" x-transition.opacity class="absolute inset-0 bg-black/55" x-on:click="field = null"></div>
        <div class="absolute inset-x-0 bottom-0 mx-auto max-w-lg rounded-t-[30px] bg-surface px-4 pb-[calc(1.25rem+env(safe-area-inset-bottom))] pt-2" x-show="field" x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0" x-transition:leave="transition duration-200 ease-in" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full">
            <div class="mx-auto h-[5px] w-9 rounded-full bg-ink/18"></div>
            <div class="mt-1 grid h-11 grid-cols-[72px_1fr_72px] items-center">
                <button type="button" class="text-left text-[15px] text-muted" x-on:click="field = null">{{ __('Cancel') }}</button>
                <div class="truncate text-center text-base font-semibold" x-text="fieldLabel"></div>
                <span></span>
            </div>
            <div class="num flex items-baseline justify-center gap-2 py-4"><span class="text-[52px] font-semibold tracking-[-0.04em]" x-text="show(value)"></span><span class="text-2xl text-muted" x-text="fieldSuffix"></span></div>
            {{-- The decimal key only where the field takes decimals (the APR); amounts in forint get 000. --}}
            <div x-show="fieldDecimals > 0"><x-ui.numpad :decimal="true" /></div>
            <div x-show="fieldDecimals === 0"><x-ui.numpad :decimal="false" /></div>
            <x-ui.button x-on:click="apply()" class="mt-3 w-full" data-test="numpad-done">{{ __('Done') }}</x-ui.button>
        </div>
    </div>
</div>
