@auth
    <x-layouts::app :title="__('Install the app')">
        <x-pages::settings.layout :heading="__('Install the app')" :subheading="__('Steps for your phone and browser')">
            <div class="rounded-card bg-surface p-[18px]">
                <x-install-guide />
            </div>
        </x-pages::settings.layout>
    </x-layouts::app>
@else
    <x-layouts::auth :title="__('Install the app')">
        <div class="flex flex-col gap-5">
            <h1 class="text-[26px] font-semibold leading-tight tracking-[-0.03em]">{{ __('Install the app') }}</h1>
            <x-install-guide />
            <x-ui.button variant="secondary" :href="route('login')" class="w-full">{{ __('Log in') }}</x-ui.button>
        </div>
    </x-layouts::auth>
@endauth
