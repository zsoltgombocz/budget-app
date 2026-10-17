<?php

use App\Actions\Budget\CompleteOnboarding;
use App\Enums\Currency;
use App\Enums\LineType;
use App\Enums\PeriodMode;
use App\Models\CategoryTemplate;
use App\Models\User;
use App\Services\AllocationCalculator;
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
    public const int STEPS = 9;

    /**
     * Draft layout version: 2 since the reserve and the leftover got a step each (8 and 9)
     * and the leftover stays on the account by default.
     */
    public const int DRAFT_VERSION = 2;

    /**
     * Wizard step => the group of plan lines it asks about.
     */
    public const array GROUP_STEPS = [4 => 'fixed', 5 => 'shared', 6 => 'loan', 7 => 'daily', 8 => 'reserve'];

    /**
     * The answers kept in the server-side draft (budget_settings.onboarding_draft).
     */
    public const array DRAFT_FIELDS = ['step', 'income', 'periodMode', 'paydayDay', 'currency', 'shared', 'hasLoan', 'amounts', 'included', 'dueDays', 'loanPrincipal', 'loanThm', 'loanMonths', 'reserveTarget', 'reservePct', 'reserveMode', 'reserveFixed', 'surplusTarget'];

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

    /** @var array<int, int|null> optional due day per fixed line (day of the month), for the due reminders */
    public array $dueDays = [];

    /** Optional loan details on the loan step; the installment is the loan line's amount. */
    public string $loanPrincipal = '';

    public string $loanThm = '';

    public string $loanMonths = '';

    public string $reserveTarget = '';

    /** Share of the month-end leftover that goes to the reserve on top of the monthly amount. */
    public int $reservePct = 0;

    /** 'pct': a share of the leftover goes to the reserve; 'fixed': a fixed amount of it. */
    public string $reserveMode = 'pct';

    public string $reserveFixed = '';

    /** Where the rest of the leftover goes: 'none' (stays on the account), 'pocket' (a Savings pocket) or 'investment'. */
    public string $surplusTarget = 'none';

    /** The answers came from a saved draft: the wizard says so until the user moves to another step. */
    public bool $resumed = false;

    public function mount(): void
    {
        $settings = $this->user()->settings();

        if ($settings->isOnboarded()) {
            $this->redirectRoute('dashboard', navigate: true);

            return;
        }

        $this->resetAnswers();

        if (is_array($settings->onboarding_draft) && $settings->onboarding_draft !== []) {
            $this->restoreDraft($settings->onboarding_draft);
            $this->resumed = true;
        }
    }

    /**
     * Every answer is saved as a draft, so a reload or a closed app resumes the wizard.
     */
    public function updated(): void
    {
        $this->saveDraft();
    }

    public function next(): void
    {
        $this->validateStep();

        $this->step = min(self::STEPS, $this->step + 1);
        $this->resumed = false;
        $this->saveDraft();
    }

    public function back(): void
    {
        $this->step = max(1, $this->step - 1);
        $this->resumed = false;
        $this->saveDraft();
    }

    /**
     * Drop the saved answers and start the wizard from the first step.
     */
    public function restart(): void
    {
        $this->user()->settings()->update(['onboarding_draft' => null]);
        $this->resetAnswers();
        $this->resetErrorBag();
        $this->resumed = false;
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

        // Same rule as at closing: the monthly saving counts towards the target first.
        $allocation = app(AllocationCalculator::class)->allocate(
            leftover: max(0, $leftover),
            reservePct: $this->reservePct,
            hasReserve: $reserveOn,
            reserveBalance: $reserveMonthly,
            reserveTarget: $target,
            reserveFixed: $this->reserveMode === 'fixed' ? (Money::parse($this->reserveFixed === '' ? '0' : $this->reserveFixed, $currency) ?? 0) : null,
        );
        $toReserve = $allocation->toReserve;

        return [
            'income' => $income,
            'planned' => $planned,
            'reserveMonthly' => $reserveMonthly,
            'leftover' => $leftover,
            'toReserve' => $toReserve,
            'toSurplus' => $allocation->toSurplus,
            'reserveOn' => $reserveOn,
        ];
    }

    public function reserveIndex(): int
    {
        return $this->indexesFor('reserve')[0];
    }

    private function resetAnswers(): void
    {
        $this->reset(self::DRAFT_FIELDS);

        $items = OnboardingItems::all();
        $this->amounts = array_fill(0, count($items), '');
        $this->dueDays = array_fill(0, count($items), null);
        $this->included = array_map(fn (array $entry): bool => ! ($entry['item']['off'] ?? false), $items);
    }

    private function saveDraft(): void
    {
        $this->user()->settings()->update(['onboarding_draft' => [...$this->only(self::DRAFT_FIELDS), 'version' => self::DRAFT_VERSION]]);
    }

    /**
     * Put the saved answers back, keeping the defaults for anything missing or malformed.
     *
     * @param  array<array-key, mixed>  $draft
     */
    private function restoreDraft(array $draft): void
    {
        $string = fn (string $key, string $default): string => is_string($draft[$key] ?? null) ? $draft[$key] : $default;
        $int = fn (string $key, ?int $default): ?int => is_int($draft[$key] ?? null) ? $draft[$key] : $default;
        $bool = fn (string $key): ?bool => is_bool($draft[$key] ?? null) ? $draft[$key] : null;

        $this->step = max(1, min(self::STEPS, (int) $int('step', 1)));
        $this->income = $string('income', '');
        $this->periodMode = $string('periodMode', $this->periodMode);
        $this->paydayDay = array_key_exists('paydayDay', $draft) ? $int('paydayDay', null) : $this->paydayDay;
        $this->currency = Currency::tryFrom($string('currency', $this->currency)) !== null ? $string('currency', $this->currency) : 'HUF';
        $this->shared = $bool('shared');
        $this->hasLoan = $bool('hasLoan');
        $this->loanPrincipal = $string('loanPrincipal', '');
        $this->loanThm = $string('loanThm', '');
        $this->loanMonths = $string('loanMonths', '');
        $this->reserveTarget = $string('reserveTarget', '');
        $this->reservePct = (int) $int('reservePct', 0);
        $this->reserveMode = $string('reserveMode', 'pct');
        $this->reserveFixed = $string('reserveFixed', '');
        $this->surplusTarget = in_array($string('surplusTarget', 'none'), ['none', 'pocket', 'investment'], true) ? $string('surplusTarget', 'none') : 'none';

        // An older draft saved "investment" as the default before the user ever saw the leftover question.
        if (($draft['version'] ?? 1) < self::DRAFT_VERSION && $this->step < 9 && $this->surplusTarget === 'investment') {
            $this->surplusTarget = 'none';
        }

        foreach (array_keys($this->amounts) as $index) {
            if (is_array($draft['amounts'] ?? null) && is_string($draft['amounts'][$index] ?? null)) {
                $this->amounts[$index] = $draft['amounts'][$index];
            }

            if (is_array($draft['included'] ?? null) && is_bool($draft['included'][$index] ?? null)) {
                $this->included[$index] = $draft['included'][$index];
            }

            $day = is_array($draft['dueDays'] ?? null) ? ($draft['dueDays'][$index] ?? null) : null;
            $this->dueDays[$index] = is_int($day) && $day >= 1 && $day <= 31 ? $day : null;
        }
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
            dueDays: array_map(fn (mixed $day): ?int => is_int($day) && $day >= 1 && $day <= 31 ? $day : null, $this->dueDays),
            // Without a reserve pocket there is no target and no share of the leftover for it.
            reserveTarget: ! $withReserve || $this->reserveTarget === '' ? null : Money::parse($this->reserveTarget, $currency),
            reservePct: $this->reservePct,
            surplusTarget: in_array($this->surplusTarget, ['pocket', 'investment'], true) ? $this->surplusTarget : 'none',
            included: $includedIndexes,
            reserveFixed: $withReserve && $this->reserveMode === 'fixed' ? (Money::parse($this->reserveFixed === '' ? '0' : $this->reserveFixed, $currency) ?? 0) : null,
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
            4 => [...$amountRules, 'dueDays.*' => ['nullable', 'integer', 'between:1,31']],
            8 => [
                ...$amountRules,
                'reserveTarget' => ['nullable', $money],
            ],
            9 => [
                'reservePct' => ['required', 'integer', 'between:0,100'],
                'reserveMode' => ['required', Rule::in(['pct', 'fixed'])],
                'reserveFixed' => ['nullable', $money],
                'surplusTarget' => ['required', Rule::in(['none', 'investment', 'pocket'])],
            ],
            default => $amountRules,
        };

        $this->validate($rules, [
            'income.required' => __('Enter your monthly net income.'),
            'paydayDay.required' => __('Pick the day you get paid.'),
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
        4 => [__('Housing and monthly fees'), __('What you pay from your own account every month. Switch off what you do not have.')],
        5 => [__('Do you share costs with someone?'), __('For example a joint account with your partner that you both transfer to every month.')],
        6 => [__('Do you have a loan?'), __('Its monthly installment is a fixed line in the plan, you do not record it as spending. The principal and the APR can come later.')],
        7 => [__('How much do you spend in a month on everyday things?'), __('A monthly budget for each, for what you record day by day. Rough numbers are fine, you can change them any time.')],
        8 => [__('Would you like a reserve?'), __('Money put aside for the unexpected. If a month ends in the red, at closing you choose whether to take the gap from here.')],
        9 => [__('What should happen to the money left at month end?'), __('At closing the app shares out what is left. You can change this later on the Plan screen.')],
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
        ...window.amountFields({ decimals: {{ $currency->decimals() }}, locale: @js(str_replace('_', '-', app()->getLocale())), fields: { value: '' }, active: 'value' }),
        field: null,
        fieldLabel: '',
        fieldDecimals: {{ $currency->decimals() }},
        fieldSuffix: @js($currency->symbol()),
        open(name, current, label = '', decimals = null, suffix = null) {
            this.field = name
            this.fieldLabel = label
            this.editAmount('value', current, label)
            this.fieldDecimals = decimals ?? this.decimals
            this.amountRules = { value: { decimals: this.fieldDecimals } }
            this.fieldSuffix = suffix ?? @js($currency->symbol())
        },
        show(raw, fallback = '0') { return window.formatAmount(raw, { locale: this.amountLocale, fallback }) },
        apply() { $wire.set(this.field, this.fields.value); this.field = null },
     }">
    <div class="grid grid-cols-[44px_1fr_44px] items-center px-3 pt-3">
        @if ($step > 1)
            <button type="button" wire:click="back" class="focus-ring flex size-11 items-center justify-center rounded-xl text-ink-2" aria-label="{{ __('Back') }}"><x-ui.icon name="arrow_back" /></button>
        @else
            <span></span>
        @endif
        <div class="text-center text-[15px] font-semibold">{{ __('Set up your budget') }}</div>
        <div class="text-center font-mono text-[13px] text-muted" aria-hidden="true">{{ $step }}/{{ $this::STEPS }}</div>
    </div>
    <x-ui.steps :current="$step" :total="$this::STEPS" />

    @if ($resumed)
        {{-- The confirm call lives on a plain element: inside a component attribute Livewire's morph compiler chokes on it. --}}
        <div class="mx-4 mt-4" x-data="{ async startOver() { if (await window.appConfirm({ title: @js(__('Start over?')), body: @js(__('Your answers so far are cleared and the wizard starts from the first step.')), confirm: @js(__('Start over')), danger: true })) $wire.restart() } }">
            {{-- One row: the note and its quiet action on the right. --}}
            <x-ui.callout icon="history" class="py-2 [&>:first-child]:mt-0 [&>:first-child]:self-center" data-test="onboarding-resumed">
                <div class="flex min-h-9 items-center justify-between gap-2">
                    <span class="min-w-0">{{ __('Picking up where you left off.') }}</span>
                    <x-ui.button variant="text" size="sm" class="-mr-2 shrink-0" x-on:click="startOver()" data-test="onboarding-restart">{{ __('Start over') }}</x-ui.button>
                </div>
            </x-ui.callout>
        </div>
    @endif

    <div wire:key="step-{{ $step }}" class="step-enter">
    <div class="px-6 pt-[22px]">
        <h1 class="text-[28px] font-semibold leading-tight tracking-[-0.03em] text-pretty">{{ $titles[$step][0] }}</h1>
        <p class="mt-1.5 text-sm leading-normal text-pretty text-muted">{{ $titles[$step][1] }}</p>
    </div>

    <div class="px-4 pt-6">
        @if ($step === 1)
            {{-- One signal that this is the field to fill: the number itself, muted while empty, and one line under it. --}}
            <button type="button" x-on:click="open('income', $wire.income, @js(__('Monthly net income')))" class="focus-ring w-full rounded-card bg-surface px-5 py-5 text-left" data-test="onboarding-income">
                <span class="block text-[13px] text-muted">{{ __('Monthly net income') }}</span>
                <span class="mt-1 block" :class="$wire.income === '' && 'text-muted'"><x-ui.amount bind="show($wire.income)" :currency="$currency" size="xl" /></span>
                <span x-show="$wire.income === ''" class="mt-2 block text-[13px] font-medium text-accent-strong" data-test="income-hint">{{ __('Tap and type it in') }}</span>
            </button>
            @error('income')<p class="mt-2 px-2 text-xs text-danger" role="alert">{{ $message }}</p>@enderror
            <p class="mt-2 px-2 text-[13px] text-muted" data-test="income-later">{{ __('You can change it any time.') }}</p>
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
                <x-ui.section-label :label="__('Monthly amounts')" class="mt-6" />
            @endif
        @endif

        @if ($showItems)
            {{-- What a 0 means (and, for fixed items, that the payment day is optional) is said once for the whole step; a 0 amount is muted in its row. --}}
            <p @class(['px-1.5 pb-3 text-[13px] leading-snug text-pretty text-muted', '-mt-1' => $question !== null]) data-test="zero-hint">
                {{ $group === 'daily'
                    ? __('A budget left at 0 means no budget for it: spending there lowers the month-end leftover.')
                    : __('A line left at 0 stays out of the plan; you can add it later on the Plan screen.').' '.__('The payment day is optional: with notifications on, the app reminds you that morning.') }}
            </p>
            <div class="flex flex-col gap-3">
                @foreach ($this->indexesFor($group) as $index)
                    @php
                        $item = $this->items[$index]['item'];
                        $on = $included[$index] ?? true;
                        $isLoan = $item['loan'] ?? false;
                        $loanFields = [
                            ['field' => 'loanPrincipal', 'label' => __('Outstanding principal'), 'decimals' => null, 'suffix' => null],
                            ['field' => 'loanThm', 'label' => __('APR (%)'), 'decimals' => 3, 'suffix' => '%'],
                            ['field' => 'loanMonths', 'label' => __('Months left'), 'decimals' => 0, 'suffix' => __('months')],
                        ];
                    @endphp
                    {{-- One card per line: a header row (icon, name, switch), then its fields as rows on the card itself. --}}
                    <div wire:key="item-{{ $index }}" class="overflow-hidden rounded-card bg-surface" data-test="template-item">
                        <div class="flex items-start gap-3 px-4 py-3.5">
                            <x-ui.icon-tile :icon="Icons::forCategory($item['icon'] ?? null)" :tone="$on ? 'default' : 'muted'" />
                            <div class="min-w-0 flex-1 self-center">
                                <div @class(['text-[15px] font-medium leading-snug', 'text-muted' => ! $on])>{{ __($item['name']) }}</div>
                                @if ($on && ! empty($item['hint']))
                                    <p class="mt-0.5 text-[13px] leading-snug text-pretty text-muted">{{ __($item['hint']) }}</p>
                                @endif
                            </div>
                            <x-ui.toggle :on="$on" wire:click="$set('included.{{ $index }}', {{ $on ? 'false' : 'true' }})" :aria-label="__($item['name'])" class="mt-2" />
                        </div>

                        @if ($on)
                            <div class="divide-y divide-line border-t border-line">
                                <div>
                                    <button type="button" x-on:click="open('amounts.{{ $index }}', $wire.amounts[{{ $index }}], @js(__($item['name'])))"
                                            class="focus-ring flex min-h-[52px] w-full items-center justify-between gap-3 px-4 py-2.5 text-left" data-test="amount-{{ $index }}">
                                        <span class="text-[15px]">{{ $group === 'daily' ? __('About, a month') : __('Monthly amount') }}</span>
                                        <span class="flex shrink-0 items-center gap-1" :class="! $wire.amounts[{{ $index }}] ? 'text-muted' : 'text-ink'"><x-ui.amount size="sm" bind="show($wire.amounts[{{ $index }}], '0')" :currency="$currency" class="text-[17px] font-semibold" /></span>
                                    </button>
                                    @error('amounts.'.$index)<p class="-mt-1 px-4 pb-2.5 text-xs text-danger" role="alert">{{ $message }}</p>@enderror
                                </div>
                                @if ($group === 'fixed')
                                    <x-ui.day-field :label="__('Which day do you pay it?')" value="$wire.dueDays[{{ $index }}]" pick="$wire.set('dueDays.{{ $index }}', day)" data-test="due-day-{{ $index }}" />
                                @endif
                                @if ($isLoan)
                                    @php
                                        $loanOpen = collect($loanFields)->contains(fn (array $detail): bool => $this->{$detail['field']} !== '' || $errors->has($detail['field']));
                                    @endphp
                                    <div x-data="{ more: @js($loanOpen) }" data-test="loan-details">
                                        <button type="button" x-on:click="more = ! more" :aria-expanded="more ? 'true' : 'false'" class="focus-ring flex min-h-[52px] w-full items-center justify-between gap-3 px-4 py-2.5 text-left" data-test="loan-more">
                                            <span class="min-w-0">
                                                <span class="block text-[15px]">{{ __('More') }}</span>
                                                <span class="mt-0.5 block text-xs leading-snug text-muted">{{ __('Loan details: principal, APR, months left (optional)') }}</span>
                                            </span>
                                            <x-ui.icon name="expand_more" :size="20" class="shrink-0 text-faint transition" ::class="more && 'rotate-180'" />
                                        </button>
                                        <div x-show="more" x-cloak class="divide-y divide-line border-t border-line">
                                            <p class="px-4 py-3 text-xs leading-snug text-pretty text-muted">{{ __('With these the app shows the payoff and what a prepayment saves. You find them on your loan statement; you can add them later too: Plan, Loan repayments.') }}</p>
                                            @foreach ($loanFields as $detail)
                                                <div>
                                                    <button type="button" x-on:click="open(@js($detail['field']), $wire.{{ $detail['field'] }}, @js($detail['label']), @js($detail['decimals']), @js($detail['suffix']))"
                                                            class="focus-ring flex min-h-[52px] w-full items-center justify-between gap-3 px-4 py-2.5 text-left" data-test="{{ $detail['field'] }}">
                                                        <span class="text-[15px]">{{ $detail['label'] }}</span>
                                                        <span class="shrink-0" :class="! $wire.{{ $detail['field'] }} ? 'text-muted' : 'text-ink'"><x-ui.amount size="sm" bind="show($wire.{{ $detail['field'] }}, '–')" :currency="$currency" :symbol="$detail['suffix']" class="text-[17px] font-semibold" /></span>
                                                    </button>
                                                    @error($detail['field'])<p class="-mt-1 px-4 pb-2.5 text-xs text-danger" role="alert">{{ $message }}</p>@enderror
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
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
            @endphp
            <div class="grid gap-2" data-test="question-reserve">
                <x-ui.choice :selected="$reserveOn" wire:click="$set('included.{{ $reserveIndex }}', true)" class="rounded-btn px-4 py-4 text-left text-[15px]" data-test="reserve-yes">{{ __('Yes, I want a reserve') }}</x-ui.choice>
                <x-ui.choice :selected="! $reserveOn" wire:click="$set('included.{{ $reserveIndex }}', false)" class="rounded-btn px-4 py-4 text-left text-[15px]" data-test="reserve-no">{{ __('Not now') }}</x-ui.choice>
            </div>
            @if ($reserveOn)
                <div class="mt-5 overflow-hidden rounded-card bg-surface" data-test="reserve-card">
                    <div class="flex items-center gap-3 px-4 py-3.5">
                        <x-ui.icon-tile icon="shield" />
                        <div class="min-w-0 flex-1 text-[15px] font-medium leading-snug">{{ __('Reserve pocket') }}</div>
                    </div>
                    <div class="divide-y divide-line border-t border-line">
                        @foreach ([
                            ['field' => 'amounts.'.$reserveIndex, 'value' => $amounts[$reserveIndex] ?? '', 'label' => __('Put aside every month'), 'hint' => __('Planned like a fixed cost and moved into the pocket at closing. 0 is fine too.'), 'empty' => money_number(0, $currency), 'symbol' => true, 'test' => 'reserve-monthly'],
                            ['field' => 'reserveTarget', 'value' => $reserveTarget, 'label' => __('Target'), 'hint' => __('Until the pocket reaches it, the month-end leftover also tops it up. Empty means no limit.'), 'empty' => '–', 'symbol' => false, 'test' => 'reserve-target'],
                        ] as $row)
                            <button type="button" x-on:click="open(@js($row['field']), @js($row['value']), @js($row['label']))" class="focus-ring flex min-h-[52px] w-full items-center justify-between gap-3 px-4 py-3 text-left" data-test="{{ $row['test'] }}">
                                <span class="min-w-0">
                                    <span class="block text-[15px]">{{ $row['label'] }}</span>
                                    <span class="mt-0.5 block text-xs leading-snug text-pretty text-muted">{{ $row['hint'] }}</span>
                                </span>
                                @if ($row['value'] === '')
                                    <span class="num inline-flex shrink-0 items-baseline gap-1 text-[17px] font-semibold text-muted">{{ $row['empty'] }}@if ($row['symbol'])<span class="text-[0.8em] font-normal">{{ $currency->symbol() }}</span>@endif</span>
                                @else
                                    <x-ui.amount size="sm" :value="Money::parse($row['value'], $currency) ?? 0" :currency="$currency" class="shrink-0 text-[17px] font-semibold" />
                                @endif
                            </button>
                        @endforeach
                    </div>
                    @error('amounts.'.$reserveIndex)<p class="px-4 pb-3 text-xs text-danger" role="alert">{{ $message }}</p>@enderror
                    @error('reserveTarget')<p class="px-4 pb-3 text-xs text-danger" role="alert">{{ $message }}</p>@enderror
                </div>
            @else
                <p class="mt-4 px-1.5 text-[13px] leading-relaxed text-muted">{{ __('No reserve pocket: the whole month-end leftover goes where you choose on the next step. You can add one later under Pockets.') }}</p>
            @endif
        @endif

        @if ($step === 9)
            @php
                $reserveOn = $included[$this->reserveIndex()] ?? true;
                $preview = $this->leftoverPreview;
                $surplusOptions = [
                    'none' => [__('Stays on the account'), __('Nothing is moved: the money simply stays on your account.')],
                    'pocket' => [__('Savings pocket'), __('At closing it goes into a “Savings” pocket in the app; you can take money out of it.')],
                    'investment' => [__('If you have an investment account'), __('At closing we remind you to move it to your investment account.')],
                ];
            @endphp
            <div class="rounded-card bg-surface px-4 py-4">
                @if ($reserveOn)
                    <div class="text-[13px] text-muted">{{ __('On top of the monthly amount, this much of the leftover also goes to the reserve until it reaches the target:') }}</div>
                    <div class="mt-2 grid grid-cols-2 gap-[3px] rounded-xl bg-bg p-[3px]">
                        <button type="button" wire:click="$set('reserveMode', 'pct')" aria-pressed="{{ $reserveMode === 'pct' ? 'true' : 'false' }}" @class(['focus-ring h-11 rounded-[9px] text-[13px] font-medium', 'bg-surface-3 text-ink' => $reserveMode === 'pct', 'text-muted' => $reserveMode !== 'pct']) data-test="reserve-mode-pct">{{ __('A share') }}</button>
                        <button type="button" wire:click="$set('reserveMode', 'fixed')" aria-pressed="{{ $reserveMode === 'fixed' ? 'true' : 'false' }}" @class(['focus-ring h-11 rounded-[9px] text-[13px] font-medium', 'bg-surface-3 text-ink' => $reserveMode === 'fixed', 'text-muted' => $reserveMode !== 'fixed']) data-test="reserve-mode-fixed">{{ __('A fixed amount') }}</button>
                    </div>
                    @if ($reserveMode === 'fixed')
                        <button type="button" x-on:click="open('reserveFixed', $wire.reserveFixed, @js(__('Amount from the leftover')))" class="focus-ring mt-2 flex h-12 w-full items-center justify-between rounded-[14px] bg-surface-2 px-4 text-left" data-test="reserve-fixed">
                            <span class="text-[13px] text-muted">{{ __('Amount from the leftover') }}</span>
                            <span :class="! $wire.reserveFixed && 'text-muted'"><x-ui.amount size="sm" bind="show($wire.reserveFixed, '0')" :currency="$currency" class="text-[17px] font-semibold" /></span>
                        </button>
                        @error('reserveFixed')<p class="mt-1.5 px-1 text-xs text-danger" role="alert">{{ $message }}</p>@enderror
                    @else
                        <div class="mt-2 grid grid-cols-4 gap-2">
                            @foreach ([0, 25, 50, 100] as $pct)
                                <x-ui.choice :selected="$reservePct === $pct" wire:click="$set('reservePct', {{ $pct }})" class="num h-11 rounded-xl text-[15px]">{{ $pct }}%</x-ui.choice>
                            @endforeach
                        </div>
                    @endif
                    <div class="mt-4 text-[13px] text-muted">{{ __('The rest goes to:') }}</div>
                @else
                    <div class="text-[13px] text-muted">{{ __('All of it goes to:') }}</div>
                @endif
                <div class="mt-2 grid gap-2" data-test="surplus-options">
                    @foreach ($surplusOptions as $value => [$optionLabel, $optionHint])
                        <x-ui.choice :selected="$surplusTarget === $value" wire:click="$set('surplusTarget', '{{ $value }}')" class="rounded-btn px-4 py-3 text-left" data-test="surplus-{{ $value }}">
                            <span class="block text-[15px] font-semibold">{{ $optionLabel }}</span>
                            <span class="mt-0.5 block text-[13px] font-normal leading-snug text-pretty text-muted">{{ $optionHint }}</span>
                        </x-ui.choice>
                    @endforeach
                </div>
            </div>

            {{-- A preview, not a warning: neutral, the numbers in ink. --}}
            <x-ui.callout class="mt-3" :title="__('Where the leftover goes')" data-test="leftover-preview">
                <div class="num mt-1.5">
                    <div class="flex justify-between gap-3 py-0.5"><span>{{ __('Income') }}</span><span class="shrink-0 text-ink">{{ money($preview['income'], $currency) }}</span></div>
                    <div class="flex justify-between gap-3 py-0.5"><span>{{ __('Planned costs and budgets') }}</span><span class="shrink-0 text-ink">{{ $preview['planned'] > 0 ? '−' : '' }}{{ money($preview['planned'], $currency) }}</span></div>
                    @if ($preview['reserveOn'])
                        <div class="flex justify-between gap-3 py-0.5"><span>{{ __('Put aside in the reserve') }}</span><span class="shrink-0 text-ink" data-test="preview-reserve-monthly">{{ $preview['reserveMonthly'] > 0 ? '−' : '' }}{{ money($preview['reserveMonthly'], $currency) }}</span></div>
                    @endif
                    <div @class(['mt-1 flex justify-between gap-3 border-t border-line pt-1.5 font-semibold', 'text-danger' => $preview['leftover'] < 0, 'text-ink' => $preview['leftover'] >= 0])><span>{{ __('Expected leftover') }}</span><span class="shrink-0">{{ $preview['leftover'] < 0 ? '−' : '' }}{{ money(abs($preview['leftover']), $currency) }}</span></div>
                </div>
                @if ($preview['leftover'] > 0)
                    <div class="mt-3 font-semibold text-ink">{{ __('Splitting the leftover') }}</div>
                    <div class="num mt-0.5">
                        @if ($preview['reserveOn'])
                            <div class="flex justify-between gap-3 py-0.5"><span>{{ __('Reserve: :fixed fixed + :share from the leftover', ['fixed' => money($preview['reserveMonthly'], $currency), 'share' => money($preview['toReserve'], $currency)]) }}</span><span class="shrink-0 font-semibold text-ink">{{ money($preview['reserveMonthly'] + $preview['toReserve'], $currency) }}</span></div>
                        @endif
                        <div class="flex justify-between gap-3 py-0.5"><span>{{ $surplusOptions[$surplusTarget][0] ?? __('Stays on the account') }}</span><span class="shrink-0 font-semibold text-ink">{{ money($preview['toSurplus'], $currency) }}</span></div>
                    </div>
                @elseif ($preview['leftover'] === 0)
                    <p class="mt-2 text-ink-2">{{ __('Your plan uses up the whole income, so nothing is left to share out.') }}</p>
                @else
                    <p class="mt-2 text-ink-2">{{ __('Your plan is :gap over the income a month.', ['gap' => money(-$preview['leftover'], $currency)]) }} {{ __('It is worth lowering a budget before you start.') }}</p>
                @endif
                <p class="mt-2 text-xs">{{ __('The real numbers come at closing, from what you actually spent. You can change all of this later on the Plan screen.') }}</p>
            </x-ui.callout>
        @endif
    </div>
    </div>

    <x-ui.action-bar>
        @if ($step === 1)
            {{-- The way out stays quiet: a text button right above Next. --}}
            <div class="flex flex-1 flex-col gap-1.5">
                <x-ui.button variant="text" size="md" wire:click="skip" class="w-full" data-test="onboarding-skip">{{ __('Skip, I set it up myself') }}</x-ui.button>
                <x-ui.button class="w-full" wire:click="next" data-test="onboarding-next">{{ __('Next') }}</x-ui.button>
            </div>
        @elseif ($step < $this::STEPS)
            @if ($question !== null && $question['value'] === null)
                <div class="flex flex-1 flex-col gap-2">
                    <p id="onboarding-next-hint" class="text-center text-xs text-muted" data-test="onboarding-next-hint">{{ __('Choose one to continue.') }}</p>
                    <x-ui.button class="w-full" disabled aria-describedby="onboarding-next-hint" data-test="onboarding-next">{{ __('Next') }}</x-ui.button>
                </div>
            @else
                <x-ui.button class="flex-1" wire:click="next" data-test="onboarding-next">{{ __('Next') }}</x-ui.button>
            @endif
        @else
            <x-ui.button class="flex-1" wire:click="finish" data-test="onboarding-next">{{ __('Start budgeting') }}</x-ui.button>
        @endif
    </x-ui.action-bar>

    {{-- Numpad sheet for every amount on the wizard; the decimal key only where the field takes decimals (the APR). --}}
    <x-ui.sheet show="field" close="field = null" title="fieldLabel" :full="false" data-test="numpad-sheet">
        <x-ui.amount-display display="show(fields.value)" label-bind="fieldLabel" symbol-bind="fieldSuffix" class="py-4" />
        <div x-show="fieldDecimals > 0"><x-ui.numpad :decimal="true" enter="apply()" /></div>
        <div x-show="fieldDecimals === 0"><x-ui.numpad :decimal="false" enter="apply()" /></div>
        <x-slot:footer>
            <x-ui.button x-on:click="apply()" class="w-full" data-test="numpad-done">{{ __('Done') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.sheet>
</div>
