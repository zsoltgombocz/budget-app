{{-- "Install the app" card: native prompt on Chrome/Android, instructions on iOS, hidden once installed. --}}
<div x-data="{
        mode: null,
        busy: false,
        init() {
            const decide = () => {
                let dismissed = false
                try { dismissed = localStorage.getItem('install-dismissed') === '1' } catch (e) {}
                if (dismissed || window.budgetInstall.installed()) { this.mode = null; return }
                this.mode = window.budgetInstall.available() ? 'prompt' : (window.budgetInstall.ios() ? 'ios' : null)
            }
            decide()
            window.addEventListener('install-available', decide)
            window.addEventListener('install-done', () => this.mode = null)
        },
        async installApp() {
            this.busy = true
            try { if (await window.budgetInstall.prompt()) this.mode = null } finally { this.busy = false }
        },
        dismiss() {
            try { localStorage.setItem('install-dismissed', '1') } catch (e) {}
            this.mode = null
        },
    }" x-show="mode" x-cloak {{ $attributes->class(['rounded-card bg-surface px-[18px] py-4']) }} data-test="install-card">
    <div class="flex items-start gap-3">
        <x-ui.icon-tile icon="install_mobile" tone="accent" />
        <div class="min-w-0 flex-1">
            <div class="text-[15px] font-semibold">{{ __('Install the app') }}</div>
            <div class="mt-0.5 text-[13px] leading-snug text-muted" x-show="mode === 'prompt'">{{ __('One tap on the home screen, works offline and sends reminders.') }}</div>
            <div class="mt-0.5 text-[13px] leading-snug text-muted" x-show="mode === 'ios'">
                {{ __('In Safari tap') }} <x-ui.icon name="ios_share" :size="16" class="-mb-0.5 text-ink-2" /> {{ __('Share, then “Add to Home Screen”. Reminders only work from the installed app on iPhone.') }}
            </div>
        </div>
        <button type="button" x-on:click="dismiss()" class="text-faint" aria-label="{{ __('Close') }}"><x-ui.icon name="close" :size="20" /></button>
    </div>
    <x-ui.button x-show="mode === 'prompt'" x-on:click="installApp()" ::disabled="busy" size="md" icon="add_box" class="mt-3 w-full" data-test="install-button">{{ __('Install') }}</x-ui.button>
</div>
