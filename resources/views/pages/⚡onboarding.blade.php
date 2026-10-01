<?php

use App\Actions\Budget\CompleteOnboarding;
use App\Enums\Currency;
use App\Enums\LineType;
use App\Enums\PeriodMode;
use App\Models\CategoryTemplate;
use App\Models\User;
use App\Support\Icons;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Set up your budget')] #[Layout('layouts::app', ['tabs' => false])] class extends Component {
    public const int STEPS = 6;

    public int $step = 1;

    public string $income = '';

    public string $periodMode = 'payday';

    public ?int $paydayDay = 10;

    public string $currency = 'HUF';

    public string $templateKey = 'basic';

    /** @var array<int, string> */
    public array $amounts = [];

    /** @var array<int, bool> */
    public array $included = [];

    public string $reserveTarget = '';

    public int $reservePct = 100;

    public string $surplusTarget = 'investment';

    public function mount(): void
    {
        if ($this->user()->settings()->isOnboarded()) {
            $this->redirectRoute('dashboard', navigate: true);
        }
    }

    public function next(): void
    {
        $this->validateStep();

        if ($this->step === 4) {
            $count = count($this->template->items);
            $this->amounts = array_fill(0, $count, '');
            $this->included = array_fill(0, $count, true);
        }

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

        $completeOnboarding->handle(
            user: $this->user(),
            income: (int) Money::parse($this->income, $currency),
            periodMode: PeriodMode::from($this->periodMode),
            paydayDay: $this->paydayDay,
            currency: $currency,
            template: $this->template,
            amounts: array_map(fn (string $amount): int => $amount === '' ? 0 : (int) Money::parse($amount, $currency), $this->amounts),
            reserveTarget: $this->reserveTarget === '' ? null : Money::parse($this->reserveTarget, $currency),
            reservePct: $this->reservePct,
            surplusTarget: $this->surplusTarget === 'pocket' ? 'pocket' : 'investment',
            included: array_keys(array_filter($this->included)),
        );

        $this->redirectRoute('notifications.onboarding', navigate: true);
    }

    #[Computed]
    public function template(): CategoryTemplate
    {
        return CategoryTemplate::query()->where('key', $this->templateKey)->firstOrFail();
    }

    /**
     * @return Collection<int, CategoryTemplate>
     */
    #[Computed]
    public function templates(): Collection
    {
        return CategoryTemplate::query()->orderBy('sort')->get();
    }

    #[Computed]
    public function currencyEnum(): Currency
    {
        return Currency::tryFrom($this->currency) ?? Currency::HUF;
    }

    private function validateStep(): void
    {
        $currency = $this->currencyEnum;
        $money = function (string $attribute, mixed $value, Closure $fail) use ($currency): void {
            if ($value !== '' && $value !== null && (! is_scalar($value) || Money::parse((string) $value, $currency) === null)) {
                $fail(__('Enter a valid amount.'));
            }
        };

        $rules = match ($this->step) {
            1 => ['income' => ['required', $money]],
            2 => [
                'periodMode' => ['required', Rule::enum(PeriodMode::class)],
                'paydayDay' => [Rule::requiredIf($this->periodMode === PeriodMode::Payday->value), 'nullable', 'integer', 'between:1,31'],
            ],
            3 => ['currency' => ['required', Rule::enum(Currency::class)]],
            4 => ['templateKey' => ['required', Rule::exists('category_templates', 'key')]],
            5 => ['amounts' => ['array'], 'amounts.*' => ['nullable', $money]],
            6 => [
                'reserveTarget' => ['nullable', $money],
                'reservePct' => ['required', 'integer', 'between:0,100'],
                'surplusTarget' => ['required', Rule::in(['investment', 'pocket'])],
            ],
            default => [],
        };

        $this->validate($rules);
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
        4 => [__('Pick a starting point'), __('Everything can be changed later.')],
        5 => [__('Fill in the amounts'), __('Only what you pay from your own account, monthly. Switch off what you do not need, leave empty what you do not know yet.')],
        6 => [__('What happens to the leftover?'), __('At month end the leftover fills the reserve first, the rest goes to your chosen target.')],
    ];
@endphp

<div class="flex min-h-[calc(100dvh-var(--safe-top)-env(safe-area-inset-bottom))] flex-col pb-[calc(10rem+env(safe-area-inset-bottom))]"
     x-data="{
        field: null,
        value: '',
        decimals: {{ $currency->decimals() }},
        formatter: new Intl.NumberFormat(@js(str_replace('_', '-', app()->getLocale())), { maximumFractionDigits: 0, useGrouping: 'always' }),
        open(name, current) { this.field = name; this.value = String(current ?? '').replace('.', ',') },
        press(key) {
            let v = this.value
            if (key === 'del') v = v.slice(0, -1)
            else if (key === ',') { if (this.decimals > 0 && ! v.includes(',')) v = (v || '0') + ',' }
            else if (key === '000') { if (v && ! v.includes(',')) v += '000' }
            else { const f = v.split(',')[1]; if (f !== undefined && f.length >= this.decimals) return; if (v === '0') v = ''; v += key }
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
        @switch($step)
            @case(1)
                <button type="button" x-on:click="open('income', $wire.income)" class="w-full rounded-card bg-surface px-5 py-6 text-left" data-test="onboarding-income">
                    <span class="block text-[13px] text-muted">{{ __('Monthly net income') }}</span>
                    <span class="num mt-1 flex items-baseline gap-2"><span class="text-[44px] font-semibold tracking-[-0.04em]" :class="$wire.income === '' && 'text-faint'" x-text="show($wire.income)"></span><span class="text-xl text-muted">{{ $currency->symbol() }}</span></span>
                </button>
                @error('income')<p class="mt-2 px-2 text-xs text-danger">{{ $message }}</p>@enderror
                @break

            @case(2)
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
                @break

            @case(3)
                <div class="grid grid-cols-4 gap-2">
                    @foreach (Currency::cases() as $option)
                        <x-ui.choice :selected="$currency === $option" wire:click="$set('currency', '{{ $option->value }}')" class="h-14 rounded-btn text-[15px]">{{ $option->value }}</x-ui.choice>
                    @endforeach
                </div>
                @break

            @case(4)
                <div class="grid gap-2">
                    @foreach ($this->templates as $template)
                        <x-ui.choice :selected="$templateKey === $template->key" wire:click="$set('templateKey', '{{ $template->key }}')" class="rounded-btn px-4 py-4 text-left" wire:key="template-{{ $template->key }}">
                            <span class="block text-[15px] font-semibold">{{ __($template->name) }}</span>
                            <span class="mt-1 block text-[13px] font-normal leading-snug text-muted">{{ __($template->description ?? '') }}</span>
                        </x-ui.choice>
                    @endforeach
                </div>
                @break

            @case(5)
                @if (empty($this->template->items))
                    <x-ui.empty-state icon="list_alt" :title="__('Empty plan')">{{ __('You start with an empty plan and add lines on the Plan screen.') }}</x-ui.empty-state>
                @else
                    <div class="flex flex-col gap-2.5">
                        @foreach ($this->template->items as $index => $item)
                            @php $on = $included[$index] ?? true; @endphp
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
                                    <button type="button" x-on:click="open('amounts.{{ $index }}', $wire.amounts[{{ $index }}])"
                                            class="mt-3 flex h-12 w-full items-center justify-between rounded-[14px] bg-surface-2 px-4 text-left" data-test="amount-{{ $index }}">
                                        <span class="text-[13px] text-muted">{{ __('Monthly amount') }}</span>
                                        <span class="num text-[17px] font-semibold">
                                            <span :class="! $wire.amounts[{{ $index }}] && 'text-faint'" x-text="show($wire.amounts[{{ $index }}], '0')"></span>
                                            <span class="text-sm font-medium text-muted">{{ $currency->symbol() }}</span>
                                        </span>
                                    </button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
                @break

            @case(6)
                <button type="button" x-on:click="open('reserveTarget', $wire.reserveTarget)" class="w-full rounded-card bg-surface px-5 py-4 text-left">
                    <span class="block text-[13px] text-muted">{{ __('Reserve target') }}</span>
                    <span class="num mt-1 flex items-baseline gap-2"><span class="text-[30px] font-semibold" :class="$wire.reserveTarget === '' && 'text-faint'" x-text="show($wire.reserveTarget, '–')"></span><span class="text-muted">{{ $currency->symbol() }}</span></span>
                    <span class="mt-1 block text-xs text-muted">{{ __('Leave empty for no reserve cap.') }}</span>
                </button>
                <div class="mt-5 px-1 text-[13px] text-muted">{{ __('Share of the leftover going to the reserve (%)') }}</div>
                <div class="mt-2 grid grid-cols-4 gap-2">
                    @foreach ([25, 50, 75, 100] as $pct)
                        <x-ui.choice :selected="$reservePct === $pct" wire:click="$set('reservePct', {{ $pct }})" class="num h-12 rounded-xl text-[15px]">{{ $pct }}%</x-ui.choice>
                    @endforeach
                </div>
                <div class="mt-5 px-1 text-[13px] text-muted">{{ __('The rest goes to') }}</div>
                <div class="mt-2 grid gap-2" data-test="surplus-options">
                    <x-ui.choice :selected="$surplusTarget === 'investment'" wire:click="$set('surplusTarget', 'investment')" class="rounded-btn px-4 py-4 text-left">
                        <span class="block text-[15px] font-semibold">{{ __('Investment account') }}</span>
                        <span class="mt-1 block text-[13px] font-normal leading-snug text-muted">{{ __('At closing the rest is listed as a manual transfer and we remind you to move it to your broker or investment account. The app does not track its balance (yet).') }}</span>
                    </x-ui.choice>
                    <x-ui.choice :selected="$surplusTarget === 'pocket'" wire:click="$set('surplusTarget', 'pocket')" class="rounded-btn px-4 py-4 text-left">
                        <span class="block text-[15px] font-semibold">{{ __('Savings pocket') }}</span>
                        <span class="mt-1 block text-[13px] font-normal leading-snug text-muted">{{ __('At closing the rest is added to a “Savings” pocket in the app: you see its balance under Pockets and can take money out of it.') }}</span>
                    </x-ui.choice>
                </div>
                <p class="mt-3 px-1 text-xs leading-snug text-muted">{{ __('This only decides where the month-end leftover goes after the reserve. You can change it later in Settings.') }}</p>
                @break
        @endswitch
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
        <div class="absolute inset-0 bg-black/55" x-on:click="field = null"></div>
        <div class="absolute inset-x-0 bottom-0 mx-auto max-w-lg rounded-t-[30px] bg-surface px-4 pb-[calc(1.25rem+env(safe-area-inset-bottom))] pt-2" x-show="field" x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0">
            <div class="mx-auto h-[5px] w-9 rounded-full bg-ink/18"></div>
            <div class="num flex items-baseline justify-center gap-2 py-5"><span class="text-[52px] font-semibold tracking-[-0.04em]" x-text="show(value)"></span><span class="text-2xl text-muted">{{ $currency->symbol() }}</span></div>
            <x-ui.numpad :decimal="$currency->decimals() > 0" />
            <x-ui.button x-on:click="apply()" class="mt-3 w-full" data-test="numpad-done">{{ __('Done') }}</x-ui.button>
        </div>
    </div>
</div>
