<?php

use App\Models\User;
use App\Models\NotificationLog;
use App\Notifications\TestReminder;
use App\Services\NotificationLogger;
use App\Services\PeriodService;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notifications')] class extends Component {
    public bool $reminderEnabled = true;

    public bool $dueReminderEnabled = true;

    public bool $versionReminderEnabled = true;

    public string $reminderTime = '20:30';

    public function mount(): void
    {
        $settings = $this->user()->settings();

        $this->reminderEnabled = $settings->reminder_enabled;
        $this->dueReminderEnabled = $settings->due_reminder_enabled;
        $this->versionReminderEnabled = $settings->version_reminder_enabled;
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
            'versionReminderEnabled' => ['boolean'],
            'reminderTime' => ['required', 'date_format:H:i'],
        ]);

        $this->user()->settings()->update([
            'reminder_enabled' => $this->reminderEnabled,
            'due_reminder_enabled' => $this->dueReminderEnabled,
            'version_reminder_enabled' => $this->versionReminderEnabled,
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

        $notification = new TestReminder(app(PeriodService::class)->today($user->settings())->toDateString());
        app(NotificationLogger::class)->queued($user, $notification);
        $user->notify($notification);
        unset($this->logs);

        $this->dispatch('app-toast', title: __('Test notification on its way.'), subtitle: __('It goes the same way as the daily reminder and arrives in a few seconds.'), icon: 'notifications');
    }

    /**
     * The latest notifications and what happened to them.
     *
     * @return Collection<int, NotificationLog>
     */
    #[Computed]
    public function logs(): Collection
    {
        return $this->user()->notificationLogs()->latest('id')->limit(10)->get();
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
                <div class="mt-4 flex items-center justify-between border-t border-line pt-4">
                    <div>
                        <div class="text-sm">{{ __('New version') }}</div>
                        <div class="mt-0.5 text-xs text-muted">{{ __('Once per release, with what is new') }}</div>
                    </div>
                    <x-ui.toggle :on="$versionReminderEnabled" wire:click="$toggle('versionReminderEnabled')" :aria-label="__('New version')" data-test="version-reminder-toggle" />
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

            @php
                $typeLabels = [
                    'daily-reminder' => __('Daily reminder'),
                    'test' => __('Test notification'),
                    'due-items-reminder' => __('Due fixed items'),
                    'payday-reminder' => __('Payday'),
                    'period-end-reminder' => __('Last day of the period'),
                    'surplus-transfer-reminder' => __('Leftover transfer'),
                    'budget-alert' => __('Budget alert'),
                    'new-version-available' => __('New version'),
                ];
                $reasons = [
                    'recorded-today' => __('skipped: you already recorded spending today'),
                    'no-spend-today' => __('skipped: you marked today as a no-spend day'),
                    'no push subscription' => __('not sent: no device has notifications turned on'),
                ];
                $timezone = auth()->user()->settings()->timezone;
            @endphp
            <x-ui.card class="p-[18px]" wire:poll.10s data-test="notification-log">
                <div class="text-[15px] font-semibold">{{ __('Recent notifications') }}</div>
                <div class="mt-0.5 text-xs text-muted">{{ __('What the app sent or skipped, and whether the push service accepted it.') }}</div>
                @forelse ($this->logs as $log)
                    <div wire:key="log-{{ $log->id }}" @class(['flex items-start gap-3 py-2.5', 'border-b border-line' => ! $loop->last, 'mt-2' => $loop->first])>
                        <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-accent' => $log->status === 'sent', 'bg-danger' => $log->status === 'failed', 'bg-warn' => $log->status === 'queued', 'bg-faint' => $log->status === 'skipped'])></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm">{{ $typeLabels[$log->type] ?? $log->type }}</span>
                            <span @class(['block text-xs', 'text-danger' => $log->status === 'failed', 'text-muted' => $log->status !== 'failed'])>
                                @switch($log->status)
                                    @case('sent') {{ __('sent, accepted by the push service') }} @break
                                    @case('queued') {{ __('waiting to be sent') }} @break
                                    @case('skipped') {{ $reasons[$log->reason] ?? $log->reason }} @break
                                    @default {{ $reasons[$log->reason] ?? __('not delivered: :reason', ['reason' => trim(($log->push_status ? $log->push_status.' ' : '').$log->reason)]) }}
                                @endswitch
                            </span>
                        </span>
                        <span class="num shrink-0 text-xs text-muted">{{ $log->created_at?->setTimezone($timezone)->format('m.d. H:i') }}</span>
                    </div>
                @empty
                    <div class="mt-3 text-sm text-muted">{{ __('Nothing yet.') }}</div>
                @endforelse
            </x-ui.card>

    </div>
</x-pages::settings.layout>
