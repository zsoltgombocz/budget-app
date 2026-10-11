<x-layouts::auth :title="__('Log in')">
    {{-- Once the code is on its way only the code field matters: the intro and the passkey button step aside. --}}
    <div class="flex flex-col gap-6" x-data="{ codeSent: false }" x-on:magic-link-step.window="codeSent = $event.detail.sent">
        <div class="flex flex-col gap-6" x-show="! codeSent" data-test="login-intro">
            <x-auth-header :title="__('Log in')" :description="__('No password: we email you a code, or use a passkey.')" />

            <x-auth-session-status :status="session('status')" />

            <x-passkey-verify />
        </div>

        <livewire:auth.magic-link-form />

        @if (config('budget.registration_open'))
            <div class="text-center text-sm text-muted">
                {{ __('Don\'t have an account?') }}
                <x-ui.link :href="route('register')" wire:navigate>{{ __('Sign up') }}</x-ui.link>
            </div>
        @else
            <p class="text-center text-sm text-muted">{{ __('The app is invite only for now.') }}</p>
        @endif
    </div>
</x-layouts::auth>
