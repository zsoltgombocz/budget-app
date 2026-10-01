<x-layouts::auth :title="__('Sign in')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Sign in')" :description="__('Tap the button to finish signing in on this device.')" />

        <form method="POST" action="{{ route('magic-link.login', $token) }}">
            @csrf
            <x-ui.button type="submit" class="w-full" data-test="magic-link-confirm">{{ __('Sign in') }}</x-ui.button>
        </form>
    </div>
</x-layouts::auth>
