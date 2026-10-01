{{-- Install steps for the visitor's phone and browser (shared by the public and in-app page). --}}
<div x-data="{ p: { route: 'none' }, all: false, init() { this.p = window.budgetInstall.platform(); window.addEventListener('install-available', () => this.p = window.budgetInstall.platform()) } }" class="flex flex-col gap-5" data-test="install-guide">
    <p class="text-sm leading-normal text-muted">{{ __('Installed it opens from the Home Screen like any app, works offline and can send the evening reminder.') }}</p>

    <template x-if="p.route === 'prompt'">
        <x-ui.button x-on:click="window.budgetInstall.prompt().then(ok => ok && (p = { route: 'none', browser: 'standalone' }))" icon="add_box" class="w-full">{{ __('Install') }}</x-ui.button>
    </template>

    <template x-if="p.browser === 'standalone'">
        <div class="flex items-center gap-2 rounded-[14px] bg-accent/12 px-4 py-3 text-sm font-medium text-accent"><span class="ms ms-fill" style="font-size:20px;width:20px;height:20px">check_circle</span>{{ __('The app is installed.') }}</div>
    </template>

    <x-install-steps />

    <template x-if="p.route === 'none' && p.browser === 'desktop'">
        <p class="text-sm text-muted">{{ __('Open this page on your phone, or install it from the address bar in Chrome or Edge on a computer.') }}</p>
    </template>

    <details class="rounded-[14px] bg-surface-2 px-4 py-3 text-sm">
        <summary class="cursor-pointer font-medium">{{ __('Other phones and browsers') }}</summary>
        <div class="mt-3 flex flex-col gap-4 text-ink-2">
            <div><b class="text-ink">iPhone · Safari</b><br>{{ __('Share → Add to Home Screen → Add.') }}</div>
            <div><b class="text-ink">iPhone · Chrome</b><br>{{ __('Share icon in the address bar → Add to Home Screen (iOS 16.4+). Missing? Edit Actions… → add it.') }}</div>
            <div><b class="text-ink">Android · Chrome</b><br>{{ __('⋮ menu → Install app.') }}</div>
            <div><b class="text-ink">Android · Samsung Internet</b><br>{{ __('Menu → Add page to → Home screen.') }}</div>
        </div>
    </details>
</div>
