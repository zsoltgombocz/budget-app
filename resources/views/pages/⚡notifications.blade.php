<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notifications')] #[Layout('layouts::app', ['tabs' => false])] class extends Component {
    /** @var list<string> */
    public const array TIMES = ['19:00', '20:00', '20:30', '21:30'];

    public string $time = '20:30';

    public bool $dueReminders = true;

    public function mount(): void
    {
        $settings = $this->user()->settings();
        $this->time = substr($settings->reminder_time, 0, 5);
        $this->dueReminders = $settings->due_reminder_enabled;
    }

    public function pick(string $time): void
    {
        if (in_array($time, self::TIMES, true)) {
            $this->time = $time;
        }
    }

    /**
     * Called after the browser granted (or refused) permission and subscribed.
     */
    public function finish(bool $enabled): void
    {
        $this->user()->settings()->update([
            'reminder_time' => $this->time,
            'reminder_enabled' => $enabled,
            'due_reminder_enabled' => $this->dueReminders,
            'notifications_onboarded_at' => now(),
        ]);

        $this->redirectRoute('dashboard', navigate: true);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

<div class="flex min-h-[calc(100dvh-env(safe-area-inset-top)-env(safe-area-inset-bottom))] flex-col"
     x-data="{
        busy: false,
        supported: window.budgetPush.supported(),
        async enable() {
            this.busy = true
            let ok = false
            try { ok = await window.budgetPush.subscribe() } catch (e) { ok = false }
            await $wire.finish(ok)
        },
     }">
    <div class="px-6 pt-10">
        <x-ui.icon-tile icon="notifications" tone="accent" :size="60" :fill="true" />
        <h1 class="mt-5 text-[30px] font-semibold leading-[1.12] tracking-[-0.03em] text-pretty">{{ __('Ten seconds a day and the month stays in order') }}</h1>
        <p class="mt-3 text-[15px] leading-normal text-pretty text-muted">{{ __('We nudge you once in the evening if you have not recorded anything that day. One tap: write down the spending or say you did not spend.') }}</p>
    </div>

    <div class="mx-4 mt-6 grid grid-cols-[38px_1fr] items-start gap-3 rounded-[20px] bg-ink/7 px-3.5 py-3">
        <x-ui.icon-tile icon="add" tone="solid" :size="38" />
        <div>
            <div class="flex justify-between text-[13px]"><b class="font-semibold">{{ config('app.name') }}</b><span class="num text-muted" x-text="$wire.time"></span></div>
            <div class="mt-[3px] text-sm leading-[1.35] text-ink-2">{{ __("What did you spend today? Record it now, or tell us you didn't spend.") }}</div>
        </div>
    </div>

    <x-ui.card class="mx-4 mt-3 rounded-[22px] px-[18px] py-4">
        <div class="flex items-center justify-between">
            <span class="text-[15px] font-medium">{{ __('Daily reminder') }}</span>
            <span class="num text-[28px] font-semibold tracking-[-0.02em]" x-text="$wire.time">{{ $time }}</span>
        </div>
        <div class="mt-3 grid grid-cols-4 gap-1.5">
            @foreach ($this::TIMES as $option)
                <x-ui.choice :selected="$time === $option" wire:click="pick('{{ $option }}')" class="num h-11 rounded-xl text-sm" wire:key="time-{{ $option }}">{{ $option }}</x-ui.choice>
            @endforeach
        </div>
        <div class="mt-3.5 flex items-center justify-between border-t border-line pt-3.5">
            <div>
                <div class="text-sm">{{ __('Fixed items due') }}</div>
                <div class="mt-0.5 text-xs text-muted">{{ __('On the due day at 8:00') }}</div>
            </div>
            <x-ui.toggle :on="$dueReminders" wire:click="$toggle('dueReminders')" :aria-label="__('Fixed items due')" />
        </div>
    </x-ui.card>

    <p class="mx-6 mt-4 text-[13px] text-muted" x-show="! supported" x-cloak>{{ __('This browser does not support push notifications. On iPhone add the app to your Home Screen first.') }}</p>

    <div class="mt-auto flex flex-col gap-1.5 px-4 pb-[42px] pt-6">
        <x-ui.button x-on:click="enable()" ::disabled="busy || ! supported" data-test="enable-notifications">{{ __('Enable notifications') }}</x-ui.button>
        <button type="button" x-on:click="try { localStorage.setItem('push-prompt-dismissed', '1') } catch (e) {}; $wire.finish(false)" class="h-11 text-[15px] text-muted" data-test="not-now">{{ __('Not now') }}</button>
    </div>
</div>
