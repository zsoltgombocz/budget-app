{{-- "Install the app" card: native prompt where available, otherwise the steps for this phone and browser. --}}
<div x-data="{
        p: { route: 'none' },
        busy: false,
        open: false,
        init() {
            const decide = () => {
                let dismissed = false
                try { dismissed = localStorage.getItem('install-dismissed') === '1' } catch (e) {}
                this.p = dismissed ? { route: 'none' } : window.budgetInstall.platform()
            }
            decide()
            window.addEventListener('install-available', decide)
            window.addEventListener('install-done', () => this.p = { route: 'none' })
        },
        async installApp() {
            this.busy = true
            try { if (await window.budgetInstall.prompt()) this.p = { route: 'none' } } finally { this.busy = false }
        },
        dismiss() {
            try { localStorage.setItem('install-dismissed', '1') } catch (e) {}
            this.p = { route: 'none' }
        },
    }" x-show="p.route !== 'none'" x-cloak {{ $attributes->class(['rounded-card bg-surface px-[18px] py-4']) }} data-test="install-card">
    <div class="flex items-start gap-3">
        <x-ui.icon-tile icon="install_mobile" tone="accent" />
        <div class="min-w-0 flex-1">
            <div class="text-[15px] font-semibold">{{ __('Install the app') }}</div>
            <div class="mt-0.5 text-[13px] leading-snug text-muted">{{ __('One tap on the home screen, works offline and sends reminders.') }}</div>
        </div>
        <button type="button" x-on:click="dismiss()" class="text-faint" aria-label="{{ __('Close') }}"><x-ui.icon name="close" :size="20" /></button>
    </div>

    <x-ui.button x-show="p.route === 'prompt'" x-on:click="installApp()" ::disabled="busy" size="md" icon="add_box" class="mt-3 w-full" data-test="install-button">{{ __('Install') }}</x-ui.button>

    <template x-if="p.route !== 'prompt'">
        <div class="mt-3">
            <x-install-steps />
            <a href="{{ route('install') }}" class="mt-3 inline-block text-[13px] font-medium text-accent">{{ __('Detailed guide') }}</a>
        </div>
    </template>
</div>
