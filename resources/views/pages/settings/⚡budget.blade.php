<?php

use App\Actions\Budget\ChangeBaseCurrency;
use App\Actions\Budget\ResetBudget;
use App\Enums\Currency;
use App\Enums\PeriodMode;
use App\Models\User;
use App\Services\Data\CurrencyChangePreview;
use App\Services\ExchangeRates\ExchangeRatesUnavailable;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Budget settings')] class extends Component {
    public string $periodMode = 'calendar';

    public ?int $paydayDay = null;

    /** Changed only through previewCurrency() + changeCurrency(), which convert the amounts. */
    #[Locked]
    public string $currency = 'HUF';

    /**
     * The currency change the user is asked to confirm, with the rates shown to them.
     *
     * @var array{from: string, to: string, restores: bool, huf_per_from: string, huf_per_to: string, rate_date: string, rates: list<array{date: string, currency: string, huf_per: string}>, example_before: int, example_after: int, lines_with_original: int}|null
     */
    #[Locked]
    public ?array $pendingCurrencyChange = null;

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
        ]);

        $user->settings()->update([
            'period_mode' => PeriodMode::from($this->periodMode),
            'payday_day' => $this->periodMode === PeriodMode::Payday->value ? $this->paydayDay : null,
        ]);

        $this->dispatch('budget-updated');
    }

    /**
     * Work out a base currency change and return the confirmation dialog's text, or null
     * (with an error shown) when MNB cannot be reached. Nothing is saved here.
     *
     * @return array{title: string, highlight: string, note: string, body: string, confirm: string}|null
     */
    public function previewCurrency(string $code, ChangeBaseCurrency $changeBaseCurrency): ?array
    {
        $this->pendingCurrencyChange = null;
        $this->resetErrorBag('currency');
        $to = Currency::tryFrom($code);

        if ($to === null || $to === $this->user()->settings()->currency) {
            return null;
        }

        try {
            $preview = $changeBaseCurrency->preview($this->user(), $to);
        } catch (ExchangeRatesUnavailable $exception) {
            report($exception);
            $this->addError('currency', __('The MNB exchange rate could not be fetched, so nothing has changed. Please try again later.'));

            return null;
        }

        $this->pendingCurrencyChange = $preview->toArray();

        return $this->confirmation($preview);
    }

    /**
     * Carry out the change the user confirmed, at the rates shown in the dialog.
     */
    public function changeCurrency(ChangeBaseCurrency $changeBaseCurrency): void
    {
        if ($this->pendingCurrencyChange === null) {
            return;
        }

        $preview = CurrencyChangePreview::fromArray($this->pendingCurrencyChange);
        $this->pendingCurrencyChange = null;

        try {
            $changeBaseCurrency->handle($this->user(), $preview);
        } catch (ValidationException $exception) {
            $this->addError('currency', (string) collect($exception->errors())->flatten()->first());

            return;
        }

        $this->currency = $preview->to->value;
        $this->dispatch('budget-updated');
        $this->dispatch('app-toast', title: $preview->restores
            ? __('Original :currency amounts restored.', ['currency' => $preview->to->value])
            : __('Amounts converted to :currency.', ['currency' => $preview->to->value]));
    }

    /**
     * The dialog: the rate the change uses as the emphasised line, the example under it, then the details.
     *
     * @return array{title: string, highlight: string, note: string, body: string, confirm: string}
     */
    private function confirmation(CurrencyChangePreview $preview): array
    {
        $example = __(':before → :after', [
            'before' => Money::of($preview->exampleBefore, $preview->from)->format(),
            'after' => Money::of($preview->exampleAfter, $preview->to)->format(),
        ]);
        $currency = $preview->to->value;
        $planOriginals = $preview->linesWithOriginal > 0 ? __('Plan lines whose original amount is in :currency take exactly that amount.', ['currency' => $currency]) : null;

        if ($preview->restores) {
            return [
                'title' => __('Back to :currency?', ['currency' => $currency]),
                'highlight' => __('The original amounts come back (switch: :rates)', ['rates' => $this->rateText($preview->rates, withSource: false)]),
                'note' => $example,
                'body' => implode("\n\n", array_filter([
                    __('Everything you have not changed since the switch gets back its exact original :currency amount. Anything added or changed since is converted back at the rate used at the switch, not at today\'s rate.', ['currency' => $currency]),
                    $planOriginals,
                ])),
                'confirm' => __('Switch back'),
            ];
        }

        return [
            'title' => __('Convert every amount to :currency?', ['currency' => $currency]),
            'highlight' => $this->rateText($preview->rates),
            'note' => $example,
            'body' => implode("\n\n", array_filter([
                __('Every amount is converted at this rate: spending, the plan, past months and closings, pockets, loans and income.'),
                $planOriginals,
                __('If you switch back to :from later, the original amounts come back exactly; anything added or changed in the meantime is converted back at this same rate.', ['from' => $preview->from->value]),
            ])),
            'confirm' => __('Convert'),
        ];
    }

    /**
     * "MNB rate 2026-10-08: 1 € = 366,45 Ft", one group per day; without the source
     * just "2026-10-08, 1 € = 366,45 Ft".
     *
     * @param  list<array{date: string, currency: string, huf_per: string}>  $rates
     */
    private function rateText(array $rates, bool $withSource = true): string
    {
        $formatter = new NumberFormatter(app()->getLocale(), NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 2);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 4);
        $groups = [];

        foreach ($rates as $rate) {
            $groups[$rate['date']][] = '1 '.Currency::from($rate['currency'])->symbol().' = '
                .str_replace("\u{202F}", "\u{00A0}", (string) $formatter->format((float) $rate['huf_per'])).' '.Currency::HUF->symbol();
        }

        $parts = [];

        foreach ($groups as $date => $items) {
            $parts[] = $withSource
                ? __('MNB rate :date: :rates', ['date' => $date, 'rates' => implode(', ', $items)])
                : $date.', '.implode(', ', $items);
        }

        return implode('; ', $parts);
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

            <x-ui.card class="p-[18px]" x-data="{
                busy: false,
                async chooseCurrency(code) {
                    if (this.busy || code === $wire.currency) return
                    this.busy = true
                    try {
                        const dialog = await $wire.previewCurrency(code)
                        if (dialog && await window.appConfirm(dialog)) await $wire.changeCurrency()
                    } finally {
                        this.busy = false
                    }
                },
            }" data-test="base-currency">
                <div class="text-[15px] font-semibold">{{ __('Base currency') }}</div>
                <div class="mt-1 text-xs leading-snug text-muted">{{ __('Changing it converts every amount at the official MNB exchange rate. Switching back restores the original amounts.') }}</div>
                <div class="mt-3 grid grid-cols-4 gap-1.5" :class="busy && 'pointer-events-none opacity-60'">
                    @foreach (Currency::cases() as $option)
                        <x-ui.choice :selected="$currency === $option->value" x-on:click="chooseCurrency('{{ $option->value }}')" class="h-11 rounded-xl text-sm" data-test="currency-{{ $option->value }}">{{ $option->value }}</x-ui.choice>
                    @endforeach
                </div>
                @error('currency')<p class="mt-2 text-xs text-danger" data-test="currency-error">{{ $message }}</p>@enderror
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
