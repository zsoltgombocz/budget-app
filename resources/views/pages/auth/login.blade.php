<x-layouts::auth :title="__('Log in')">
    {{-- Once the code is on its way only the code field matters: the intro and the passkey button step aside. --}}
    <div class="flex flex-col gap-6" x-data="{ codeSent: false }" x-on:magic-link-step.window="codeSent = $event.detail.sent">
        {{-- Email first: it works for everyone; the passkey is the quick way back in, below it. --}}
        <div class="flex flex-col gap-6" x-show="! codeSent" data-test="login-intro">
            <x-auth-header :title="__('Log in')" :description="__('No password: we email you a code, or use a passkey.')" />

            <x-auth-session-status :status="session('status')" />
        </div>

        <livewire:auth.magic-link-form />

        <div x-show="! codeSent" class="-mt-2 empty:hidden" data-test="login-passkey"><x-passkey-verify /></div>

        @if (config('budget.registration_open'))
            <div class="text-center text-sm text-muted" x-show="! codeSent">
                {{ __('Don\'t have an account?') }}
                <x-ui.link :href="route('register')" wire:navigate>{{ __('Sign up') }}</x-ui.link>
            </div>
        @else
            <p class="text-center text-sm text-muted" x-show="! codeSent" data-test="invite-only">{{ __('The app is invite only for now.') }}</p>
        @endif
    </div>
</x-layouts::auth>
