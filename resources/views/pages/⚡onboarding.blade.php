<?php

use App\Actions\Month\StartBudget;
use App\Enums\PeriodMode;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The 2.0 onboarding (S02–S06): four questions and the "aha", Morzsa asking. The answers are kept
 * as a draft on the server after every step, so a closed app goes on where it was.
 */
new #[Title('Set up your budget')] #[Layout('layouts::app', ['tabs' => false])] class extends Component {
    private const array DRAFT_FIELDS = ['step', 'income', 'periodMode', 'paydayDay', 'lines', 'savings', 'savingsGoal', 'single', 'singleTotal'];

    private const int DRAFT_VERSION = 2;

    public int $step = 1;

    /** Typed amounts as on the numpad ("450000"). */
    public string $income = '';

    public string $periodMode = 'calendar';

    public ?int $paydayDay = null;

    /** @var list<array{name: string, icon: string, amount: string, due_day: int|null, loan?: bool}> */
    public array $lines = [];

    public string $savings = '';

    public string $savingsGoal = '';

    /** "Csak egy összeget írok be": one total for every fixed expense. */
    public bool $single = false;

    public string $singleTotal = '';

    public function mount(): void
    {
        $settings = $this->user()->settings();

        if ($settings->isOnboarded()) {
            $this->redirectRoute('dashboard', navigate: true);

            return;
        }

        $this->lines = $this->templateLines();
        $draft = $settings->onboarding_draft;

        if (is_array($draft) && ($draft['version'] ?? null) === self::DRAFT_VERSION) {
            foreach (self::DRAFT_FIELDS as $field) {
                if (array_key_exists($field, $draft) && (gettype($draft[$field]) === gettype($this->{$field}) || ($field === 'paydayDay' && (is_int($draft[$field]) || $draft[$field] === null)))) {
                    $this->{$field} = $draft[$field];
                }
            }
        }
    }

    /**
     * @return array{ok: bool, error: string|null}
     */
    public function setAmount(string $field, string $value, ?int $index = null): array
    {
        $amount = $value === '' ? 0 : Money::parse($value, $this->currency());

        if ($amount === null) {
            return ['ok' => false, 'error' => __('Enter a valid amount.')];
        }

        $typed = $amount === 0 ? '' : Money::toInput($amount, $this->currency());

        match ($field) {
            'income' => $this->income = $typed,
            'savings' => $this->savings = $typed,
            'singleTotal' => $this->singleTotal = $typed,
            default => $index !== null && isset($this->lines[$index]) ? $this->lines[$index]['amount'] = $typed : null,
        };

        $this->saveDraft();

        return ['ok' => true, 'error' => null];
    }

    public function setDay(int $index, ?int $day): void
    {
        if (isset($this->lines[$index])) {
            $this->lines[$index]['due_day'] = $day !== null && $day >= 1 && $day <= 31 ? $day : null;
            $this->saveDraft();
        }
    }

    public function addLine(string $name): void
    {
        if (trim($name) !== '') {
            $this->lines[] = ['name' => mb_substr(trim($name), 0, 60), 'icon' => 'receipt_long', 'amount' => '', 'due_day' => null];
            $this->saveDraft();
        }
    }

    public function next(): void
    {
        $this->resetErrorBag();

        if ($this->step === 1 && $this->amount($this->income) < 1) {
            $this->addError('income', __('Enter your monthly income.'));

            return;
        }

        if ($this->step === 2 && ! in_array($this->periodMode, ['calendar', 'payday'], true)) {
            $this->addError('periodMode', __('Choose when your month starts.'));

            return;
        }

        if ($this->step === 2 && $this->periodMode === 'payday' && $this->paydayDay === null) {
            $this->addError('paydayDay', __('Choose the day your pay comes.'));

            return;
        }

        $this->step = min(4, $this->step + 1);
        $this->saveDraft();
    }

    public function back(): void
    {
        $this->step = max(1, $this->step - 1);
        $this->saveDraft();
    }

    public function finish(StartBudget $startBudget): void
    {
        $fixed = $this->single
            ? [['name' => __('Fixed expenses'), 'icon' => 'receipt_long', 'amount' => $this->amount($this->singleTotal), 'due_day' => null]]
            : array_map(fn (array $line): array => ['name' => $line['name'], 'icon' => $line['icon'], 'amount' => $this->amount($line['amount']), 'due_day' => $line['due_day'], 'loan' => $line['loan'] ?? false], $this->lines);

        try {
            $startBudget->handle(
                $this->user(),
                $this->amount($this->income),
                $this->periodMode === 'payday' ? PeriodMode::Payday : PeriodMode::Calendar,
                $this->paydayDay,
                $fixed,
                ['amount' => $this->amount($this->savings), 'goal' => $this->savingsGoal !== '' ? $this->savingsGoal : null],
            );
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        }

        $this->redirectRoute('notifications.onboarding', navigate: true);
    }

    /**
     * The aha numbers: income − fixed − savings = the month's money, and per day.
     *
     * @return array{income: int, fixed: int, savings: int, spendable: int, days: int, daily: int}
     */
    #[Computed]
    public function aha(): array
    {
        $fixed = $this->single ? $this->amount($this->singleTotal) : array_sum(array_map(fn (array $line): int => $this->amount($line['amount']), $this->lines));
        $income = $this->amount($this->income);
        $savings = $this->amount($this->savings);
        $settings = $this->user()->settings();
        $mode = $this->periodMode === 'payday' ? PeriodMode::Payday : PeriodMode::Calendar;
        $today = resolve(\App\Services\PeriodService::class)->today($settings);
        [$start, $end] = resolve(\App\Services\PeriodService::class)->boundsFor($mode, $this->paydayDay, $today);
        $days = (int) $today->diffInDays($end) + 1;
        $spendable = $income - $fixed - $savings;

        return ['income' => $income, 'fixed' => $fixed, 'savings' => $savings, 'spendable' => $spendable, 'days' => $days, 'daily' => $spendable > 0 ? intdiv($spendable, $days) : 0];
    }

    private function amount(string $typed): int
    {
        return $typed === '' ? 0 : (Money::parse($typed, $this->currency()) ?? 0);
    }

    private function currency(): \App\Enums\Currency
    {
        return $this->user()->settings()->currency;
    }

    private function saveDraft(): void
    {
        unset($this->aha);
        $this->user()->settings()->update(['onboarding_draft' => [...$this->only(self::DRAFT_FIELDS), 'version' => self::DRAFT_VERSION]]);
    }

    /**
     * @return list<array{name: string, icon: string, amount: string, due_day: int|null, loan?: bool}>
     */
    private function templateLines(): array
    {
        return [
            ['name' => __('Housing'), 'icon' => 'home', 'amount' => '', 'due_day' => null],
            ['name' => __('Utilities'), 'icon' => 'bolt', 'amount' => '', 'due_day' => null],
            ['name' => __('Phone and internet'), 'icon' => 'smartphone', 'amount' => '', 'due_day' => null],
            ['name' => __('Loan'), 'icon' => 'account_balance', 'amount' => '', 'due_day' => null, 'loan' => true],
            ['name' => __('Subscriptions'), 'icon' => 'subscriptions', 'amount' => '', 'due_day' => null],
            ['name' => __('Transport pass'), 'icon' => 'directions_bus', 'amount' => '', 'due_day' => null],
        ];
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
    $aha = $this->aha;
    $total = $single ? (Money::parse($singleTotal ?: '0', $currency) ?? 0) : array_sum(array_map(fn ($line) => Money::parse($line['amount'] ?: '0', $currency) ?? 0, $lines));
    $questions = [
        1 => [__('How much money comes in a month?'), __('Salary, pension, anything regular.'), 'kivancsi'],
        2 => [__('When does your month start?'), null, 'kivancsi'],
        3 => [__('What goes out every month on its own?'), __('I take these off in advance, you do not record them.'), 'nyugodt'],
    ];
@endphp

<div class="flex min-h-[calc(100dvh-var(--safe-top))] flex-col px-5 pb-[calc(20px+env(safe-area-inset-bottom))]"
     x-data="onboardingPage({ decimals: @js($currency->decimals()), locale: @js(app()->getLocale()) })" data-test="onboarding">
    <div class="-mx-3 flex h-[52px] items-center justify-between">
        @if ($step > 1)
            <x-ui.icon-button icon="chevron_left" :label="__('Back')" wire:click="back" data-test="onboarding-back" />
        @else
            <span class="w-11"></span>
        @endif
        <div class="flex items-center gap-1.5" aria-label="{{ __('Step :step of :count', ['step' => $step, 'count' => 4]) }}">
            @for ($i = 1; $i <= 4; $i++)
                <span @class(['h-2 rounded-full', 'w-6 bg-accent' => $i === $step, 'w-2 bg-surface-3' => $i !== $step])></span>
            @endfor
        </div>
        <span class="w-11"></span>
    </div>

    @if ($step <= 3)
        @php [$question, $subline, $mood] = $questions[$step]; @endphp
        <div class="mt-1 flex items-end gap-2.5">
            <x-morzsa :mood="$mood" :size="64" />
            <div class="relative flex-1 rounded-[20px] bg-bubble px-4 py-3 shadow-e1 before:absolute before:bottom-4 before:-left-1.5 before:size-3.5 before:rotate-45 before:rounded-[3px] before:bg-bubble">
                <span class="block text-xl leading-[26px] font-semibold tracking-[-0.01em]">{{ $question }}</span>
                @if ($subline)<span class="mt-1 block text-sm font-medium text-muted">{{ $subline }}</span>@endif
            </div>
        </div>
    @endif

    @if ($step === 1)
        <button type="button" class="mx-auto mt-8 flex flex-col items-center" x-on:click="edit('income', null, @js($income), @js(__('Monthly income')))" data-test="income">
            <x-ui.amount :value="Money::parse($income ?: '0', $currency) ?? 0" size="display" :tone="$income === '' ? 'muted' : null" />
            <span class="mt-2 max-w-[290px] text-center text-[13px] text-muted">{{ __('Not sure? Write a round number, you can correct it later.') }}</span>
        </button>
        @error('income')<p class="mt-2 text-center text-sm text-danger" role="alert">{{ $message }}</p>@enderror
    @elseif ($step === 2)
        <div class="mt-7 flex flex-col gap-2.5" role="radiogroup" aria-label="{{ __('When does your month start?') }}">
            @foreach (['calendar' => [__('On the 1st'), __('Calendar month'), 'calendar_month'], 'payday' => [__('On payday'), __('If you go by your pay'), 'payments']] as $value => [$title, $sub, $icon])
                <button type="button" role="radio" aria-checked="{{ $periodMode === $value ? 'true' : 'false' }}" wire:click="$set('periodMode', '{{ $value }}')"
                        @class(['flex min-h-[72px] items-center gap-3 rounded-[20px] px-4 text-left', 'border-2 border-accent bg-accent-tint' => $periodMode === $value, 'border border-control-border bg-surface' => $periodMode !== $value]) data-test="mode-{{ $value }}">
                    <x-ui.icon :name="$icon" />
                    <span class="flex flex-col"><span class="text-[17px] font-semibold">{{ $title }}</span><span class="text-[13px] text-muted">{{ $sub }}</span></span>
                </button>
            @endforeach
        </div>
        @if ($periodMode === 'payday')
            <p class="mt-5 text-[13px] font-medium text-ink-2">{{ __('Which day does your pay come?') }}</p>
            <div class="mt-3 grid grid-cols-7 gap-1.5" role="radiogroup" aria-label="{{ __('Day of the month') }}">
                @for ($d = 1; $d <= 31; $d++)
                    <button type="button" wire:click="$set('paydayDay', {{ $d }})" @class(['num h-11 rounded-xl text-[15px]', 'bg-accent font-semibold text-accent-ink' => $paydayDay === $d, 'bg-surface-2' => $paydayDay !== $d])>{{ $d }}</button>
                @endfor
            </div>
            @error('paydayDay')<p class="mt-2 text-sm text-danger" role="alert">{{ $message }}</p>@enderror
        @endif
    @elseif ($step === 3)
        @if ($single)
            <button type="button" class="mx-auto mt-8 flex flex-col items-center" x-on:click="edit('singleTotal', null, @js($singleTotal), @js(__('Fixed expenses in total')))" data-test="single-total">
                <span class="text-[13px] text-muted">{{ __('Fixed expenses in total') }}</span>
                <x-ui.amount :value="$total" size="display" />
            </button>
            <button type="button" class="mx-auto mt-2 text-[15px] font-semibold text-accent-strong" wire:click="$set('single', false)">{{ __('I’d rather go line by line') }}</button>
        @else
            <div class="mt-3 flex flex-col" data-test="fixed-rows">
                @foreach ($lines as $index => $line)
                    <div class="flex min-h-[54px] items-center gap-3" wire:key="line-{{ $index }}">
                        <x-ui.icon-tile :icon="$line['icon']" :size="36" />
                        <button type="button" class="flex min-w-0 flex-1 flex-col text-left" x-on:click="pickDay({{ $index }}, @js($line['name']))">
                            <span class="truncate text-base font-medium">{{ $line['name'] }}</span>
                            <span class="text-xs text-muted">{{ $line['due_day'] ? __('every month on the :day', ['day' => app()->getLocale() === 'hu' ? \App\Support\Hungarian::onDay($line['due_day']) : $line['due_day'].'.']) : __('+ which day?') }}</span>
                        </button>
                        <button type="button" x-on:click="edit('line', {{ $index }}, @js($line['amount']), @js($line['name']))" @class(['num flex h-11 min-w-[120px] items-center justify-end rounded-xl bg-surface-2 px-3 text-base', 'font-semibold' => $line['amount'] !== '', 'text-faint' => $line['amount'] === '']) data-test="line-amount">
                            {{ $line['amount'] !== '' ? money(Money::parse($line['amount'], $currency) ?? 0) : '– '.$currency->symbol() }}
                        </button>
                    </div>
                @endforeach
                <div class="flex min-h-[54px] items-center gap-3">
                    <x-ui.icon-tile icon="savings" :size="36" tone="accent" />
                    <span class="flex min-w-0 flex-1 flex-col"><span class="truncate text-base font-medium">{{ __('Savings line') }}</span><span class="text-xs text-muted">{{ __('if you set some aside every month') }}</span></span>
                    <button type="button" x-on:click="edit('savings', null, @js($savings), @js(__('Monthly savings')))" @class(['num flex h-11 min-w-[120px] items-center justify-end rounded-xl bg-surface-2 px-3 text-base', 'font-semibold' => $savings !== '', 'text-faint' => $savings === '']) data-test="savings-amount">
                        {{ $savings !== '' ? money(Money::parse($savings, $currency) ?? 0) : '– '.$currency->symbol() }}
                    </button>
                </div>
                <form class="mt-1 flex gap-2" x-on:submit.prevent="$wire.addLine(other); other = ''" x-data="{ other: '' }">
                    <input type="text" x-model="other" maxlength="60" placeholder="{{ __('+ Other (e.g. shared account)') }}" class="h-11 min-w-0 flex-1 rounded-xl bg-surface-2 px-3.5 text-[15px] outline-none placeholder:text-muted" aria-label="{{ __('Other fixed expense') }}" data-test="add-line">
                    <x-ui.button size="sm" variant="secondary" type="submit" x-show="other.trim() !== ''">{{ __('Add') }}</x-ui.button>
                </form>
            </div>
            <button type="button" class="mx-auto mt-3 text-[15px] font-medium text-ink-2" wire:click="$set('single', true)" data-test="single">{{ __('I just write one amount.') }}</button>
        @endif
    @else
        <div class="mt-4 flex justify-center"><x-morzsa mood="unneplo" :size="120" /></div>
        <x-ui.card class="mt-5 px-4 py-2.5" data-test="aha">
            <dl class="num text-base">
                <div class="flex h-9 items-center justify-between text-ink-2"><dt>{{ __('Income') }}</dt><dd class="font-semibold text-ink">{{ money($aha['income']) }}</dd></div>
                <div class="flex h-9 items-center justify-between text-ink-2"><dt>{{ __('Fixed expenses') }}</dt><dd class="font-semibold text-ink">− {{ money($aha['fixed']) }}</dd></div>
                @if ($aha['savings'] > 0)<div class="flex h-9 items-center justify-between text-ink-2"><dt>{{ __('Savings line') }}</dt><dd class="font-semibold text-ink">− {{ money($aha['savings']) }}</dd></div>@endif
                <div class="mt-1 flex h-9 items-center justify-between border-t-2 border-ink/70 font-semibold"><dt>{{ __('For this month') }}</dt><dd>{{ money($aha['spendable']) }}</dd></div>
            </dl>
        </x-ui.card>
        <div class="mt-6 flex flex-col items-center text-center">
            <span class="font-display text-[28px] leading-8 font-bold">{{ __('For this month:') }}</span>
            <x-ui.amount :value="$aha['spendable']" size="hero" :tone="$aha['spendable'] < 0 ? 'warn' : null" class="mt-1" />
            <span class="text-[13px] text-muted">{{ __(':amount a day (:spendable ÷ :days days)', ['amount' => money($aha['daily']), 'spendable' => money(max(0, $aha['spendable'])), 'days' => $aha['days']]) }}</span>
            @if ($aha['spendable'] < 0)
                <p class="mt-3 text-[15px] text-ink-2">{{ __('The fixed expenses are more than the income. Check the amounts, or start and correct them later.') }}</p>
            @endif
        </div>
        @error('income')<p class="mt-2 text-center text-sm text-danger" role="alert">{{ $message }}</p>@enderror
    @endif

    <div class="min-h-6 flex-1"></div>

    @if ($step === 3 && ! $single)
        <div class="mb-2 flex items-center justify-between border-t border-line pt-3"><span class="text-[13px] text-muted">{{ __('In total') }}</span><span class="num text-xl font-semibold" data-test="fixed-total">{{ money($total) }}</span></div>
    @endif
    @if ($step < 4)
        <x-ui.button wire:click="next" class="w-full" data-test="onboarding-next">{{ __('Next') }}</x-ui.button>
    @else
        <x-ui.button wire:click="finish" class="w-full" data-test="onboarding-finish">{{ __('Let’s start') }}</x-ui.button>
    @endif

    <x-ui.numpad-sheet show="editor !== null" close="editor = null" title="editorTitle" done="save()" display="display('value')" :decimal="$currency->decimals() > 0" error="editorError" data-test="onboarding-editor" />

    <x-ui.sheet show="dayFor !== null" close="dayFor = null" title="dayTitle" :full="false" data-test="day-sheet">
        <p class="pb-2 text-center text-[13px] text-muted">{{ __('Which day does it go out? I tell you on that morning. You can set it later too.') }}</p>
        <div class="grid grid-cols-7 gap-1.5">
            @for ($d = 1; $d <= 31; $d++)
                <button type="button" x-on:click="$wire.setDay(dayFor, {{ $d }}); dayFor = null" class="num h-11 rounded-xl bg-surface-2 text-[15px]">{{ $d }}</button>
            @endfor
        </div>
        <x-ui.button variant="text" size="md" class="mt-2 w-full" x-on:click="$wire.setDay(dayFor, null); dayFor = null">{{ __('No day') }}</x-ui.button>
    </x-ui.sheet>
</div>

@script
<script>
    Alpine.data('onboardingPage', ({ decimals, locale }) => ({
        ...window.amountFields({ decimals, locale, fields: { value: '' }, active: 'value', rules: { value: { maxWhole: 9 } } }),
        editor: null,
        index: null,
        editorTitle: '',
        editorError: null,
        dayFor: null,
        dayTitle: '',

        edit(field, index, value, title) {
            this.editor = field
            this.index = index
            this.editAmount('value', value, title)
            this.editorTitle = title
            this.editorError = null
        },
        async save() {
            const result = await $wire.setAmount(this.editor, this.fields.value || '', this.index)
            this.editorError = result.error
            if (result.ok) this.editor = null
        },
        pickDay(index, name) {
            this.dayFor = index
            this.dayTitle = name
        },
    }))
</script>
@endscript
