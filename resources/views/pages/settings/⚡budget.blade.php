<?php

use App\Actions\Budget\ResetBudget;
use App\Enums\Currency;
use App\Enums\PeriodMode;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Budget settings')] class extends Component {
    public string $periodMode = 'calendar';

    public ?int $paydayDay = null;

    public string $currency = 'HUF';


    public function mount(): void
    {
        $settings = $this->user()->settings();

        $this->periodMode = $settings->period_mode->value;
        $this->paydayDay = $settings->payday_day;
        $this->currency = $settings->currency->value;
    }

    /**
     * Every choice is saved right away; a payday period waits until the day is picked.
     */
    public function updated(): void
    {
        if ($this->periodMode === PeriodMode::Payday->value && $this->paydayDay === null) {
            return;
        }

        $this->save();
    }

    public function save(): void
    {
        $user = $this->user();

        $this->validate([
            'periodMode' => ['required', Rule::enum(PeriodMode::class)],
            'paydayDay' => [Rule::requiredIf($this->periodMode === PeriodMode::Payday->value), 'nullable', 'integer', 'between:1,31'],
            'currency' => ['required', Rule::enum(Currency::class)],
        ]);

        $user->settings()->update([
            'period_mode' => PeriodMode::from($this->periodMode),
            'payday_day' => $this->periodMode === PeriodMode::Payday->value ? $this->paydayDay : null,
            'currency' => Currency::from($this->currency),
        ]);

        $this->dispatch('budget-updated');
    }

    public function resetBudget(ResetBudget $resetBudget): void
    {
        $resetBudget->handle($this->user());

        $this->redirectRoute('onboarding', navigate: true);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

<section class="w-full">
    <x-pages::settings.layout :heading="__('Budget')" :subheading="__('Period and base currency')">
        <form wire:submit="save" class="flex flex-col gap-3">
            <x-ui.card class="p-[18px]">
                <div class="text-[15px] font-semibold">{{ __('Period') }}</div>
                <div class="mt-3 grid grid-cols-2 gap-[3px] rounded-xl bg-bg p-[3px]">
                    @foreach (PeriodMode::cases() as $mode)
                        <button type="button" wire:click="$set('periodMode', '{{ $mode->value }}')" @class(['h-10 rounded-[9px] text-[13px] font-medium', 'bg-surface-3 text-ink' => $periodMode === $mode->value, 'text-muted' => $periodMode !== $mode->value])>{{ $mode->label() }}</button>
                    @endforeach
                </div>
                @if ($periodMode === 'payday')
                    <div class="mt-4 text-[13px] text-muted">{{ __('Payday (day of month)') }}</div>
                    <div class="mt-2 grid grid-cols-7 gap-1.5">
                        @for ($day = 1; $day <= 31; $day++)
                            <x-ui.choice :selected="$paydayDay === $day" wire:click="$set('paydayDay', {{ $day }})" class="num aspect-square rounded-xl text-sm">{{ $day }}</x-ui.choice>
                        @endfor
                    </div>
                    @error('paydayDay')<p class="mt-2 text-xs text-danger">{{ $message }}</p>@enderror
                @endif
            </x-ui.card>

            <x-ui.card class="p-[18px]">
                <div class="text-[15px] font-semibold">{{ __('Base currency') }}</div>
                <div class="mt-1 text-xs text-muted">{{ __('Changing it does not convert existing amounts.') }}</div>
                <div class="mt-3 grid grid-cols-4 gap-1.5">
                    @foreach (Currency::cases() as $option)
                        <x-ui.choice :selected="$currency === $option->value" wire:click="$set('currency', '{{ $option->value }}')" class="h-11 rounded-xl text-sm">{{ $option->value }}</x-ui.choice>
                    @endforeach
                </div>
            </x-ui.card>

            <x-ui.card class="p-[18px]">
                <div class="text-[15px] font-semibold">{{ __('Month-end leftover') }}</div>
                <div class="mt-1 text-[13px] leading-snug text-muted">{{ __('Where the leftover goes is set on the Plan screen, next to the numbers it is worked out from.') }}</div>
                <x-ui.button variant="secondary" size="md" :href="route('plan')" wire:navigate class="mt-3">{{ __('Open the plan') }}</x-ui.button>
            </x-ui.card>

        </form>

        <x-ui.card class="mt-6 border border-danger/28 !bg-danger/8 p-[18px]" x-data="{ confirming: false }">
            <div class="text-[15px] font-semibold">{{ __('Reset budget') }}</div>
            <div class="mt-1 text-[13px] leading-snug text-muted">{{ __('Deletes the plan, every period, spending, pocket and loan, then starts onboarding again. Your login stays.') }}</div>
            <x-ui.button x-show="! confirming" variant="danger" size="md" icon="history" x-on:click="confirming = true" class="mt-3" data-test="reset-budget">{{ __('Reset budget') }}</x-ui.button>
            <div x-show="confirming" x-cloak class="mt-3 flex gap-2">
                <x-ui.button variant="secondary" size="md" x-on:click="confirming = false">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="danger" size="md" wire:click="resetBudget" class="flex-1 !bg-danger !text-white" data-test="confirm-reset">{{ __('Yes, delete everything') }}</x-ui.button>
            </div>
        </x-ui.card>
    </x-pages::settings.layout>
</section>
