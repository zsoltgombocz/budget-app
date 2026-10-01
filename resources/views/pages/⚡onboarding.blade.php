<?php

use App\Actions\Budget\CompleteOnboarding;
use App\Enums\Currency;
use App\Enums\LineType;
use App\Enums\PeriodMode;
use App\Models\CategoryTemplate;
use App\Models\User;
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
            $this->amounts = array_fill(0, count($this->template->items), '');
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
        );

        $this->redirectRoute('dashboard', navigate: true);
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

    private function validateStep(): void
    {
        $currency = Currency::tryFrom($this->currency) ?? Currency::HUF;
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

<div class="flex flex-col gap-6">
    <div class="flex flex-col gap-2">
        <flux:text class="text-sm">{{ __('Step :step of :total', ['step' => $step, 'total' => $this::STEPS]) }}</flux:text>
        <flux:progress :value="$step / $this::STEPS * 100" />
    </div>

    <form wire:submit="{{ $step === $this::STEPS ? 'finish' : 'next' }}" class="flex flex-col gap-6">
        @switch($step)
            @case(1)
                <div class="flex flex-col gap-2">
                    <flux:heading size="xl">{{ __('How much do you earn?') }}</flux:heading>
                    <flux:text>{{ __('Your monthly net income. The plan starts from this.') }}</flux:text>
                </div>
                <flux:input wire:model="income" :label="__('Monthly net income')" inputmode="decimal" autofocus required data-test="onboarding-income" />
                @break

            @case(2)
                <div class="flex flex-col gap-2">
                    <flux:heading size="xl">{{ __('When does your month start?') }}</flux:heading>
                    <flux:text>{{ __('Plan from payday to payday, or by calendar month.') }}</flux:text>
                </div>
                <flux:radio.group wire:model.live="periodMode" variant="cards" class="flex-col">
                    @foreach (PeriodMode::cases() as $mode)
                        <flux:radio :value="$mode->value" :label="$mode->label()" />
                    @endforeach
                </flux:radio.group>
                @if ($periodMode === 'payday')
                    <flux:input type="number" wire:model="paydayDay" :label="__('Payday (day of month)')" min="1" max="31" inputmode="numeric" />
                @endif
                @break

            @case(3)
                <div class="flex flex-col gap-2">
                    <flux:heading size="xl">{{ __('Which currency?') }}</flux:heading>
                    <flux:text>{{ __('Every amount in the plan is in this currency.') }}</flux:text>
                </div>
                <flux:select wire:model="currency" :label="__('Base currency')">
                    @foreach (Currency::cases() as $option)
                        <flux:select.option :value="$option->value">{{ $option->value }}</flux:select.option>
                    @endforeach
                </flux:select>
                @break

            @case(4)
                <div class="flex flex-col gap-2">
                    <flux:heading size="xl">{{ __('Pick a starting point') }}</flux:heading>
                    <flux:text>{{ __('Everything can be changed later.') }}</flux:text>
                </div>
                <flux:radio.group wire:model="templateKey" variant="cards" class="flex-col">
                    @foreach ($this->templates as $template)
                        <flux:radio :value="$template->key" :label="__($template->name)" :description="__($template->description ?? '')" />
                    @endforeach
                </flux:radio.group>
                @break

            @case(5)
                <div class="flex flex-col gap-2">
                    <flux:heading size="xl">{{ __('Fill in the amounts') }}</flux:heading>
                    <flux:text>{{ __('Monthly amounts. Leave empty what you do not know yet.') }}</flux:text>
                </div>
                @if (empty($this->template->items))
                    <flux:callout icon="information-circle">
                        <flux:callout.text>{{ __('You start with an empty plan and add lines on the Plan screen.') }}</flux:callout.text>
                    </flux:callout>
                @endif
                <div class="flex flex-col gap-3">
                    @foreach ($this->template->items as $index => $item)
                        <div wire:key="amount-{{ $index }}" class="flex items-center gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium">{{ __($item['name']) }}</div>
                                <div class="text-xs text-zinc-500">{{ LineType::from($item['type'])->label() }}</div>
                            </div>
                            <flux:input wire:model="amounts.{{ $index }}" inputmode="decimal" class="!w-32" :aria-label="__($item['name'])" />
                        </div>
                    @endforeach
                </div>
                @break

            @case(6)
                <div class="flex flex-col gap-2">
                    <flux:heading size="xl">{{ __('What happens to the leftover?') }}</flux:heading>
                    <flux:text>{{ __('At month end the leftover fills the reserve first, the rest goes to your chosen target.') }}</flux:text>
                </div>
                <flux:input wire:model="reserveTarget" :label="__('Reserve target')" :description="__('Leave empty for no reserve cap.')" inputmode="decimal" />
                <flux:input type="number" wire:model="reservePct" :label="__('Share of the leftover going to the reserve (%)')" min="0" max="100" inputmode="numeric" />
                <flux:radio.group wire:model="surplusTarget" :label="__('The rest goes to')" variant="cards" class="flex-col">
                    <flux:radio value="investment" :label="__('Investment account')" />
                    <flux:radio value="pocket" :label="__('Savings pocket')" />
                </flux:radio.group>
                @break
        @endswitch

        <div class="flex gap-2">
            @if ($step > 1)
                <flux:button wire:click="back" variant="ghost" icon="arrow-left">{{ __('Back') }}</flux:button>
            @endif
            <flux:spacer />
            <flux:button type="submit" variant="primary" data-test="onboarding-next">
                {{ $step === $this::STEPS ? __('Start budgeting') : __('Next') }}
            </flux:button>
        </div>
    </form>
</div>
