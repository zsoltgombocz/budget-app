<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Log in')" :description="__('No password: we email you a code, or use a passkey.')" />

        <x-auth-session-status :status="session('status')" />

        <x-passkey-verify />

        <livewire:auth.magic-link-form />

        @if (config('budget.registration_open'))
            <div class="text-center text-sm text-muted">
                {{ __('Don\'t have an account?') }}
                <a href="{{ route('register') }}" wire:navigate class="font-medium text-accent">{{ __('Sign up') }}</a>
            </div>
        @else
            <p class="text-center text-sm text-muted">{{ __('The app is invite only for now.') }}</p>
        @endif
    </div>
</x-layouts::auth>
