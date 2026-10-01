<?php

use App\Actions\Budget\ResetBudget;
use App\Enums\Currency;
use App\Enums\PeriodMode;
use App\Models\Account;
use App\Models\Pocket;
use App\Models\User;
use App\Notifications\DailyReminder;
use App\Services\PeriodService;
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

    public bool $dueReminderEnabled = true;

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
        $this->dueReminderEnabled = $settings->due_reminder_enabled;
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
            'dueReminderEnabled' => ['boolean'],
            'reminderTime' => ['required', 'date_format:H:i'],
            'reservePct' => ['required', 'integer', 'between:0,100'],
            'surplusTarget' => ['nullable', 'regex:/^(account|pocket):\d+$/'],
        ]);

        [$targetType, $targetId] = array_pad(explode(':', $this->surplusTarget, 2), 2, null);
        $accountId = $targetType === 'account' ? $user->accounts()->whereKey((int) $targetId)->value('id') : null;
        $pocketId = $targetType === 'pocket' ? $user->pockets()->whereKey((int) $targetId)->value('id') : null;

        $localeChanged = $user->settings()->locale !== $this->locale;

        $user->settings()->update([
            'period_mode' => PeriodMode::from($this->periodMode),
            'payday_day' => $this->periodMode === PeriodMode::Payday->value ? $this->paydayDay : null,
            'currency' => Currency::from($this->currency),
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'reminder_enabled' => $this->reminderEnabled,
            'due_reminder_enabled' => $this->dueReminderEnabled,
            'reminder_time' => $this->reminderTime,
            'reserve_pct' => $this->reservePct,
            'surplus_account_id' => $accountId,
            'surplus_pocket_id' => $pocketId,
        ]);

        app()->setLocale($this->locale);
        $this->dispatch('budget-updated');

        if ($localeChanged) {
            $this->redirectRoute('budget.edit', navigate: true);

            return;
        }

        $this->dispatch('app-toast', title: __('Settings saved.'));
    }

    public function sendTestNotification(): void
    {
        $user = $this->user();

        if (! $user->pushSubscriptions()->exists()) {
            $this->dispatch('app-toast', title: __('Turn on notifications on this device first.'), icon: 'info');

            return;
        }

        $user->notifyNow(new DailyReminder(app(PeriodService::class)->today($user->settings())->toDateString()));

        $this->dispatch('app-toast', title: __('Test notification sent.'), icon: 'notifications');
    }

    public function resetBudget(ResetBudget $resetBudget): void
    {
        $resetBudget->handle($this->user());

        $this->redirectRoute('onboarding', navigate: true);
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

    <x-pages::settings.layout>
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
                <div class="mt-4 text-[15px] font-semibold">{{ __('Language') }}</div>
                <div class="mt-3 grid grid-cols-2 gap-1.5">
                    <x-ui.choice :selected="$locale === 'hu'" wire:click="$set('locale', 'hu')" class="h-11 rounded-xl text-sm">Magyar</x-ui.choice>
                    <x-ui.choice :selected="$locale === 'en'" wire:click="$set('locale', 'en')" class="h-11 rounded-xl text-sm">English</x-ui.choice>
                </div>
                <div class="mt-4">
                    <x-ui.select wire:model="timezone" :label="__('Timezone')">
                        @foreach (DateTimeZone::listIdentifiers() as $zone)
                            <option value="{{ $zone }}">{{ $zone }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
            </x-ui.card>

            <x-ui.card class="p-[18px]">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-[15px] font-semibold">{{ __('Daily reminder') }}</div>
                        <div class="mt-0.5 text-xs text-muted">{{ __('Only if you have not recorded anything that day.') }}</div>
                    </div>
                    <x-ui.toggle :on="$reminderEnabled" wire:click="$toggle('reminderEnabled')" :aria-label="__('Daily reminder')" />
                </div>
                @if ($reminderEnabled)
                    <div class="mt-3 grid grid-cols-4 gap-1.5">
                        @foreach (['19:00', '20:00', '20:30', '21:30'] as $option)
                            <x-ui.choice :selected="$reminderTime === $option" wire:click="$set('reminderTime', '{{ $option }}')" class="num h-11 rounded-xl text-sm">{{ $option }}</x-ui.choice>
                        @endforeach
                    </div>
                    <label class="mt-2 flex h-11 items-center justify-between rounded-xl bg-surface-2 px-3.5 text-sm">
                        <span class="text-muted">{{ __('Other time') }}</span>
                        <input type="time" wire:model.live="reminderTime" class="num bg-transparent text-right outline-none">
                    </label>
                @endif
                <div class="mt-4 flex items-center justify-between border-t border-line pt-4">
                    <div>
                        <div class="text-sm">{{ __('Fixed items due') }}</div>
                        <div class="mt-0.5 text-xs text-muted">{{ __('On the due day at 8:00') }}</div>
                    </div>
                    <x-ui.toggle :on="$dueReminderEnabled" wire:click="$toggle('dueReminderEnabled')" :aria-label="__('Fixed items due')" />
                </div>

                <div x-data="{
                        state: 'unknown',
                        async refresh() {
                            if (! window.budgetPush.supported()) { this.state = 'unsupported'; return }
                            if (window.budgetPush.permission() === 'denied') { this.state = 'denied'; return }
                            this.state = (await window.budgetPush.current()) ? 'on' : 'off'
                        },
                        async toggle() {
                            if (this.state === 'on') await window.budgetPush.unsubscribe()
                            else await window.budgetPush.subscribe()
                            await this.refresh()
                        },
                    }" x-init="refresh()" class="mt-4 border-t border-line pt-4" data-test="push-device">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-sm">{{ __('Notifications on this device') }}</div>
                            <div class="mt-0.5 text-xs text-muted" x-show="state === 'on'">{{ __('On') }}</div>
                            <div class="mt-0.5 text-xs text-muted" x-show="state === 'off'">{{ __('Off') }}</div>
                            <div class="mt-0.5 text-xs text-muted" x-show="state === 'unsupported'">{{ __('This browser does not support push notifications. On iPhone add the app to your Home Screen first.') }}</div>
                            <div class="mt-0.5 text-xs text-warn" x-show="state === 'denied'">{{ __('Notifications are blocked in the browser settings.') }}</div>
                        </div>
                        <button type="button" role="switch" x-show="state === 'on' || state === 'off'" x-on:click="toggle()" class="flex h-8 w-[52px] shrink-0 rounded-2xl p-[3px]" :class="state === 'on' ? 'justify-end bg-accent' : 'justify-start bg-zinc-600'"><span class="block size-[26px] rounded-full bg-white"></span></button>
                    </div>
                    <x-ui.button x-show="state === 'on'" variant="secondary" size="sm" icon="notifications" wire:click="sendTestNotification" class="mt-3">{{ __('Send a test') }}</x-ui.button>
                </div>
            </x-ui.card>

            <x-ui.card class="p-[18px]">
                <div class="text-[15px] font-semibold">{{ __('Share of the leftover going to the reserve (%)') }}</div>
                <div class="mt-3 grid grid-cols-5 gap-1.5">
                    @foreach ([0, 25, 50, 75, 100] as $pct)
                        <x-ui.choice :selected="$reservePct === $pct" wire:click="$set('reservePct', {{ $pct }})" class="num h-11 rounded-xl text-sm">{{ $pct }}%</x-ui.choice>
                    @endforeach
                </div>
                <div class="mt-4">
                    <x-ui.select wire:model="surplusTarget" :label="__('The rest goes to')">
                        <option value="">{{ __('Stays on the account') }}</option>
                        @foreach ($this->accounts as $account)
                            <option value="account:{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                        @foreach ($this->pockets as $pocket)
                            <option value="pocket:{{ $pocket->id }}">{{ $pocket->name }}</option>
                        @endforeach
                    </x-ui.select>
                    <p class="mt-2 text-xs leading-snug text-muted">{{ __('Account: listed as a manual transfer at closing. Pocket: added to the pocket balance in the app.') }}</p>
                </div>
            </x-ui.card>

            <x-ui.button type="submit" class="w-full" data-test="save-budget-settings">{{ __('Save') }}</x-ui.button>
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
