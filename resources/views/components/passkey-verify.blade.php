@props([
    'optionsRoute' => 'passkey.login-options',
    'submitRoute' => 'passkey.login',
    'label' => __('Sign in with a passkey'),
    'hint' => __('Face ID, fingerprint or your phone’s screen lock.'),
    'loadingLabel' => __('Authenticating...'),
    'separator' => __('or'),
])

@assets
@vite('resources/js/passkeys.js')
@endassets

<div
    x-data="{
        supported: false,
        loading: false,
        error: null,
        updateSupport() {
            this.supported = Boolean(window.Passkeys?.isSupported());
        },
        init() {
            this.updateSupport();

            window.addEventListener('passkeys:ready', () => this.updateSupport(), { once: true });
        },
        async verify() {
            this.loading = true;
            this.error = null;
            try {
                const response = await window.Passkeys.verify({
                    routes: {
                        options: '{{ route($optionsRoute) }}',
                        submit: '{{ route($submitRoute) }}',
                    },
                });
                Livewire.navigate(response.redirect || '/ma');
            } catch (e) {
                // Cancelling the system sheet is not an error.
                const text = [e?.name, e?.constructor?.name, e?.message].join(' ');
                this.error = /cancel|abort|NotAllowed/i.test(text) ? null : @js(__('Signing in with a passkey did not work. Try again or use the email code.'));
            } finally {
                this.loading = false;
            }
        },
    }"
>
    <template x-if="supported">
        <div>
            <div class="mb-4 flex items-center gap-3 text-[13px] text-muted" aria-hidden="true">
                <span class="h-px flex-1 bg-line"></span>{{ $separator }}<span class="h-px flex-1 bg-line"></span>
            </div>
            {{-- One line, the same shape as every other button; what a passkey is goes in the line under it. --}}
            <x-ui.button variant="secondary" class="w-full" x-on:click="verify()" ::disabled="loading" aria-describedby="passkey-hint" data-test="passkey-sign-in">
                <flux:icon.finger-print class="size-[22px] shrink-0" />
                <span x-show="!loading" class="truncate">{{ $label }}</span>
                <span x-show="loading" x-cloak class="truncate">{{ $loadingLabel }}</span>
            </x-ui.button>
            <p id="passkey-hint" class="mt-2 text-center text-[13px] leading-snug text-pretty text-muted">{{ $hint }}</p>
            <p x-show="error" x-text="error" x-cloak class="mt-2 text-center text-[13px] leading-snug text-danger" role="alert"></p>
        </div>
    </template>
</div>
