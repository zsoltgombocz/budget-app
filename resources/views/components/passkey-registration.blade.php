@assets
@vite('resources/js/passkeys.js')
@endassets

<div
    x-data="{
        supported: false,
        showForm: false,
        name: '',
        loading: false,
        error: null,
        updateSupport() {
            this.supported = Boolean(window.Passkeys?.isSupported());
        },
        getDefaultPasskeyName() {
            const ua = navigator.userAgent;

            const browser = [
                { pattern: /Edg|Edge/, name: 'Edge' },
                { pattern: /OPR|Opera|OPiOS/, name: 'Opera' },
                { pattern: /Firefox|FxiOS/, name: 'Firefox' },
                { pattern: /Chrome|CriOS/, name: 'Chrome' },
                { pattern: /Safari/, name: 'Safari' },
            ].find(({ pattern }) => pattern.test(ua))?.name;

            const os = [
                { pattern: /iPhone/, name: 'iPhone' },
                { pattern: /iPad|Macintosh(?=.*Mobile)/, name: 'iPad' },
                { pattern: /Android/, name: 'Android' },
                { pattern: /Mac/, name: 'Mac' },
                { pattern: /Windows/, name: 'Windows' },
            ].find(({ pattern }) => pattern.test(ua))?.name;

            return [browser, os].filter(Boolean).join(' on ') || '';
        },
        init() {
            this.name = this.getDefaultPasskeyName();
            this.updateSupport();

            window.addEventListener('passkeys:ready', () => this.updateSupport(), { once: true });
        },
        async register() {
            if (!this.name.trim()) return;

            this.loading = true;
            this.error = null;

            try {
                await window.Passkeys.register({ name: this.name });
                this.name = '';
                this.showForm = false;
                await $wire.loadPasskeys();
            } catch (e) {
                const text = [e?.name, e?.constructor?.name, e?.message].join(' ');
                this.error = /cancel|abort|NotAllowed/i.test(text) ? null : @js(__('Adding the passkey did not work. Try again.'));
            } finally {
                this.loading = false;
            }
        },
        cancel() {
            this.showForm = false;
            this.name = '';
            this.error = null;
        },
    }"
>
    <template x-if="!supported">
        <x-ui.callout>{{ __('Passkeys are not supported in this browser.') }}</x-ui.callout>
    </template>

    <template x-if="supported && !showForm">
        <div>
            <x-ui.button icon="add" class="w-full" x-on:click="showForm = true" data-test="add-passkey">{{ __('Add passkey') }}</x-ui.button>
        </div>
    </template>

    <template x-if="supported && showForm">
        <div class="flex flex-col gap-3 rounded-card bg-surface p-[18px]">
            <x-ui.input
                :label="__('Passkey name')"
                x-model="name"
                :placeholder="__('e.g., MacBook Pro, iPhone')"
                x-on:keydown.enter.prevent="register()"
                x-ref="passkeyNameInput"
                x-init="$nextTick(() => $refs.passkeyNameInput?.focus())"
                :hint="__('Give this passkey a name to help you identify it later.')"
                data-test="passkey-name"
            />

            <p x-show="error" x-text="error" x-cloak class="text-[13px] leading-snug text-danger" role="alert"></p>

            {{-- Inside a card: the tonal button is the main action, Cancel a quiet one next to it. --}}
            <div class="flex gap-2">
                <x-ui.button variant="text" size="md" x-on:click="cancel()">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button variant="tonal" size="md" class="flex-1" x-on:click="register()" ::disabled="loading || ! name.trim()" data-test="register-passkey">
                    <span x-show="!loading">{{ __('Register passkey') }}</span>
                    <span x-show="loading" x-cloak>{{ __('Registering...') }}</span>
                </x-ui.button>
            </div>
        </div>
    </template>
</div>
