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
            const done = () => this.p = { route: 'none' }
            decide()
            window.addEventListener('install-available', decide)
            window.addEventListener('install-done', done)
            // The card is re-created on every page visit: take the listeners down with it.
            this.unlisten = () => {
                window.removeEventListener('install-available', decide)
                window.removeEventListener('install-done', done)
            }
        },
        destroy() {
            this.unlisten?.()
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
            <div class="mt-0.5 text-[13px] leading-snug text-muted">{{ __('One tap on the home screen, full screen, and it can send reminders.') }}</div>
        </div>
        <x-ui.icon-button icon="close" :label="__('Close')" x-on:click="dismiss()" class="-mr-1.5 -mt-1" data-test="install-dismiss" />
    </div>

    <x-ui.button x-show="p.route === 'prompt'" x-on:click="installApp()" ::disabled="busy" variant="tonal" size="md" icon="add_box" class="mt-3 w-full" data-test="install-button">{{ __('Install') }}</x-ui.button>

    <template x-if="p.route !== 'prompt'">
        <div class="mt-3">
            <x-install-steps />
            <x-ui.link :href="route('install')" class="mt-1 min-h-11 text-[13px]">{{ __('Detailed guide') }}</x-ui.link>
        </div>
    </template>
</div>
