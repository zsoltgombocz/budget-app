<?php

use App\Enums\Currency;
use App\Enums\PeriodMode;
use App\Models\Account;
use App\Models\Pocket;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Budget settings')] class extends Component {
    public string $periodMode = 'calendar';

    public ?int $paydayDay = null;

    public string $currency = 'HUF';

    public string $locale = 'hu';

    public string $timezone = 'Europe/Budapest';

    public bool $reminderEnabled = true;

    public string $reminderTime = '20:30';

    public int $reservePct = 100;

    public string $surplusTarget = '';

    public function mount(): void
    {
        $settings = $this->user()->settings();

        $this->periodMode = $settings->period_mode->value;
        $this->paydayDay = $settings->payday_day;
        $this->currency = $settings->currency->value;
        $this->locale = $settings->locale;
        $this->timezone = $settings->timezone;
        $this->reminderEnabled = $settings->reminder_enabled;
        $this->reminderTime = substr($settings->reminder_time, 0, 5);
        $this->reservePct = $settings->reserve_pct;
        $this->surplusTarget = match (true) {
            $settings->surplus_account_id !== null => 'account:'.$settings->surplus_account_id,
            $settings->surplus_pocket_id !== null => 'pocket:'.$settings->surplus_pocket_id,
            default => '',
        };
    }

    public function save(): void
    {
        $user = $this->user();

        $this->validate([
            'periodMode' => ['required', Rule::enum(PeriodMode::class)],
            'paydayDay' => [Rule::requiredIf($this->periodMode === PeriodMode::Payday->value), 'nullable', 'integer', 'between:1,31'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'locale' => ['required', Rule::in(['hu', 'en'])],
            'timezone' => ['required', 'timezone:all'],
            'reminderEnabled' => ['boolean'],
            'reminderTime' => ['required', 'date_format:H:i'],
            'reservePct' => ['required', 'integer', 'between:0,100'],
            'surplusTarget' => ['nullable', 'regex:/^(account|pocket):\d+$/'],
        ]);

        [$targetType, $targetId] = array_pad(explode(':', $this->surplusTarget, 2), 2, null);
        $accountId = $targetType === 'account' ? $user->accounts()->whereKey((int) $targetId)->value('id') : null;
        $pocketId = $targetType === 'pocket' ? $user->pockets()->whereKey((int) $targetId)->value('id') : null;

        $user->settings()->update([
            'period_mode' => PeriodMode::from($this->periodMode),
            'payday_day' => $this->periodMode === PeriodMode::Payday->value ? $this->paydayDay : null,
            'currency' => Currency::from($this->currency),
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'reminder_enabled' => $this->reminderEnabled,
            'reminder_time' => $this->reminderTime,
            'reserve_pct' => $this->reservePct,
            'surplus_account_id' => $accountId,
            'surplus_pocket_id' => $pocketId,
        ]);

        app()->setLocale($this->locale);

        Flux::toast(variant: 'success', text: __('Settings saved.'));
    }

    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function accounts(): Collection
    {
        return $this->user()->accounts()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Pocket>
     */
    #[Computed]
    public function pockets(): Collection
    {
        return $this->user()->pockets()->where('is_reserve', false)->orderBy('sort')->get();
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Budget')" :subheading="__('Period, currency, language and reminders')">
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:radio.group wire:model.live="periodMode" :label="__('Period')" variant="segmented">
                @foreach (PeriodMode::cases() as $mode)
                    <flux:radio :value="$mode->value" :label="$mode->label()" />
                @endforeach
            </flux:radio.group>

            @if ($periodMode === 'payday')
                <flux:input type="number" wire:model="paydayDay" :label="__('Payday (day of month)')" min="1" max="31" inputmode="numeric" />
            @endif

            <flux:select wire:model="currency" :label="__('Base currency')" :description="__('Changing it does not convert existing amounts.')">
                @foreach (Currency::cases() as $option)
                    <flux:select.option :value="$option->value">{{ $option->value }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="locale" :label="__('Language')">
                <flux:select.option value="hu">Magyar</flux:select.option>
                <flux:select.option value="en">English</flux:select.option>
            </flux:select>

            <flux:select wire:model="timezone" :label="__('Timezone')">
                @foreach (DateTimeZone::listIdentifiers() as $zone)
                    <flux:select.option :value="$zone">{{ $zone }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:separator />

            <flux:switch wire:model="reminderEnabled" :label="__('Daily reminder')" :description="__('Only if you have not recorded anything that day.')" />
            <flux:input type="time" wire:model="reminderTime" :label="__('Reminder time')" />

            <flux:separator />

            <flux:input type="number" wire:model="reservePct" :label="__('Share of the leftover going to the reserve (%)')" min="0" max="100" inputmode="numeric" />

            <flux:select wire:model="surplusTarget" :label="__('The rest goes to')">
                <flux:select.option value="">{{ __('Stays on the account') }}</flux:select.option>
                @foreach ($this->accounts as $account)
                    <flux:select.option :value="'account:'.$account->id">{{ $account->name }}</flux:select.option>
                @endforeach
                @foreach ($this->pockets as $pocket)
                    <flux:select.option :value="'pocket:'.$pocket->id">{{ $pocket->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div>
                <flux:button type="submit" variant="primary" data-test="save-budget-settings">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
