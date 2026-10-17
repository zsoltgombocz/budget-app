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

<div class="flex min-h-[calc(100dvh-var(--safe-top)-env(safe-area-inset-bottom))] flex-col pb-[calc(10rem+env(safe-area-inset-bottom))]"
     x-data="{
        busy: false,
        supported: window.budgetPush.supported(),
        notNow() {
            try { localStorage.setItem('push-prompt-dismissed', '1') } catch (e) {}
            $wire.finish(false)
        },
        async enable() {
            this.busy = true
            let ok = false
            try { ok = await window.budgetPush.subscribe() } catch (e) { ok = false }
            await $wire.finish(ok)
        },
     }">
    <div class="px-6 pt-10">
        <x-ui.icon-tile icon="notifications" tone="accent" :size="60" :fill="true" />
        <h1 class="mt-5 text-[30px] font-semibold leading-[1.12] tracking-[-0.03em] text-balance">{{ __('Ten seconds a day and the month stays in order') }}</h1>
        <p class="mt-3 text-[15px] leading-normal text-pretty text-muted">{{ __('We nudge you once in the evening if you have not recorded anything that day. One tap: write down the spending or say you did not spend.') }}</p>
    </div>

    <div class="mx-4 mt-6 grid grid-cols-[38px_1fr] items-start gap-3 rounded-[20px] bg-ink/7 px-3.5 py-3">
        <x-ui.icon-tile icon="add" tone="solid" :size="38" />
        <div>
            {{-- The same title and text as the DailyReminder push; the system shows the installed app's name above it. --}}
            <div class="flex justify-between text-[13px]"><b class="font-semibold">MoneySight</b><span class="num text-muted" x-text="$wire.time"></span></div>
            <div class="mt-[3px] text-sm font-semibold leading-[1.35]" data-test="preview-title">{{ __('Did you record today\'s spending?') }}</div>
            <div class="text-sm leading-[1.35] text-ink-2">{{ __('One tap and you are done.') }}</div>
        </div>
    </div>

    <x-ui.card class="mx-4 mt-3 px-[18px] py-4">
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

    {{-- Without push support the enable button would only look broken: it goes, Next takes its place and this says why. --}}
    <template x-if="! supported">
        <x-ui.callout class="mx-4 mt-3" data-test="push-unsupported">{{ __('This browser does not support push notifications. On iPhone add the app to your Home Screen first.') }}</x-ui.callout>
    </template>

    {{-- The choice stays in reach on a short screen: the same fixed bar as the setup wizard before it. --}}
    <x-ui.action-bar>
        <template x-if="supported">
            <div class="flex flex-1 flex-col gap-1.5">
                <x-ui.button variant="text" size="md" x-on:click="notNow()" class="w-full" data-test="not-now">{{ __('Not now') }}</x-ui.button>
                <x-ui.button x-on:click="enable()" ::disabled="busy" class="w-full" data-test="enable-notifications">{{ __('Enable notifications') }}</x-ui.button>
            </div>
        </template>
        <template x-if="! supported">
            <x-ui.button x-on:click="$wire.finish(false)" class="flex-1" data-test="notifications-next">{{ __('Next') }}</x-ui.button>
        </template>
    </x-ui.action-bar>
</div>
