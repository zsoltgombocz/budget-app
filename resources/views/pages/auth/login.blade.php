<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Log in')" :description="__('No password: we email you a sign-in link, or use a passkey.')" />

        <x-auth-session-status :status="session('status')" />

        <x-passkey-verify />

        <livewire:auth.magic-link-form />

        <div class="text-center text-sm text-muted">
            {{ __('Don\'t have an account?') }}
            <a href="{{ route('register') }}" wire:navigate class="font-medium text-accent">{{ __('Sign up') }}</a>
        </div>
    </div>
</x-layouts::auth>
