<?php

use App\Models\User;
use App\Notifications\DailyReminder;
use App\Services\PeriodService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notifications')] class extends Component {
    public bool $reminderEnabled = true;

    public bool $dueReminderEnabled = true;

    public string $reminderTime = '20:30';

    public function mount(): void
    {
        $settings = $this->user()->settings();

        $this->reminderEnabled = $settings->reminder_enabled;
        $this->dueReminderEnabled = $settings->due_reminder_enabled;
        $this->reminderTime = substr($settings->reminder_time, 0, 5);
    }

    /**
     * Every change is saved right away.
     */
    public function updated(): void
    {
        $this->validate([
            'reminderEnabled' => ['boolean'],
            'dueReminderEnabled' => ['boolean'],
            'reminderTime' => ['required', 'date_format:H:i'],
        ]);

        $this->user()->settings()->update([
            'reminder_enabled' => $this->reminderEnabled,
            'due_reminder_enabled' => $this->dueReminderEnabled,
            'reminder_time' => $this->reminderTime,
        ]);
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

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

<x-pages::settings.layout :heading="__('Notifications')" :subheading="__('Daily reminder, due items, this device')">
    <div class="flex flex-col gap-3">
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
                        <button type="button" role="switch" x-show="state === 'on' || state === 'off'" x-on:click="toggle()" class="flex h-6 w-10 shrink-0 rounded-full p-0.5" :class="state === 'on' ? 'justify-end bg-accent' : 'justify-start bg-zinc-600'"><span class="block size-5 rounded-full bg-white"></span></button>
                    </div>
                    <x-ui.button x-show="state === 'on'" variant="secondary" size="sm" icon="notifications" wire:click="sendTestNotification" class="mt-3">{{ __('Send a test') }}</x-ui.button>
                </div>
            </x-ui.card>

    </div>
</x-pages::settings.layout>
