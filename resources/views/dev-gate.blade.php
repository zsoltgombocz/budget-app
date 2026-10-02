<x-layouts::auth :title="__('Dev version')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Dev version')" :description="__('This is the test version of the app. Enter the shared password to continue.')" />

        {{-- One submit only: the button turns into a busy state until the next page loads. --}}
        <form method="POST" action="{{ route('dev-gate.check') }}" class="flex flex-col gap-4"
              onsubmit="const button = this.querySelector('[data-test=dev-gate-submit]'); if (button.disabled) return false; button.disabled = true; button.querySelector('[data-label]').textContent = button.dataset.busy">
            @csrf
            <x-ui.input type="password" name="password" :label="__('Password')" autocomplete="current-password" required autofocus :error="$errors->first('password')" data-test="dev-gate-password" />
            <x-ui.button type="submit" class="w-full disabled:animate-pulse" data-test="dev-gate-submit" :data-busy="__('Checking…')"><span data-label>{{ __('Continue') }}</span></x-ui.button>
        </form>
    </div>
</x-layouts::auth>
