<x-layouts::auth :title="__('Dev version')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Dev version')" :description="__('This is the test version of the app. Enter the shared password to continue.')" />

        <form method="POST" action="{{ route('dev-gate.check') }}" class="flex flex-col gap-4">
            @csrf
            <x-ui.input type="password" name="password" :label="__('Password')" autocomplete="current-password" required autofocus :error="$errors->first('password')" data-test="dev-gate-password" />
            <x-ui.button type="submit" class="w-full" data-test="dev-gate-submit">{{ __('Continue') }}</x-ui.button>
        </form>
    </div>
</x-layouts::auth>
