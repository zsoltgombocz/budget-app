@props(['reminderTime'])

{{-- One-time prompt after installing: enable notifications and pick the reminder time. --}}
<div x-data="{
        visible: false,
        iosHint: false,
        busy: false,
        time: @js($reminderTime),
        init() {
            let dismissed = false
            try { dismissed = localStorage.getItem('push-prompt-dismissed') === '1' } catch (e) {}
            const ios = /iPad|iPhone|iPod/.test(navigator.userAgent)
            this.iosHint = ! dismissed && ios && ! window.budgetPush.standalone()
            this.visible = ! dismissed && window.budgetPush.supported() && window.budgetPush.permission() === 'default'
        },
        dismiss() {
            try { localStorage.setItem('push-prompt-dismissed', '1') } catch (e) {}
            this.visible = false
            this.iosHint = false
        },
        async enable() {
            this.busy = true
            try {
                await $wire.saveReminderTime(this.time)
                if (await window.budgetPush.subscribe()) this.dismiss()
                else this.visible = false
            } finally {
                this.busy = false
            }
        },
    }" x-cloak>
    <template x-if="visible">
        <flux:card class="flex flex-col gap-3" data-test="push-prompt">
            <div class="flex items-start gap-3">
                <flux:icon.bell-alert class="size-6 shrink-0 text-emerald-600" />
                <div>
                    <flux:heading>{{ __('Daily reminder') }}</flux:heading>
                    <flux:text class="text-sm">{{ __('We remind you in the evening if you have not recorded anything that day.') }}</flux:text>
                </div>
            </div>
            <div class="flex items-end gap-2">
                <flux:input type="time" x-model="time" :label="__('Reminder time')" class="flex-1" />
                <flux:button variant="primary" x-on:click="enable()" ::disabled="busy">{{ __('Turn on') }}</flux:button>
            </div>
            <flux:button size="sm" variant="ghost" x-on:click="dismiss()">{{ __('Not now') }}</flux:button>
        </flux:card>
    </template>

    <template x-if="! visible && iosHint">
        <flux:callout icon="device-phone-mobile">
            <flux:callout.text>{{ __('Add the app to your Home Screen (Share → Add to Home Screen) to get reminders on iPhone.') }}</flux:callout.text>
            <x-slot name="controls">
                <flux:button size="xs" variant="ghost" icon="x-mark" x-on:click="dismiss()" :aria-label="__('Close')" />
            </x-slot>
        </flux:callout>
    </template>
</div>
