<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark" @env('staging') data-build="dev" @endenv>
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-dvh bg-bg font-sans text-ink antialiased">
        <x-splash />
        <div id="status-shield" aria-hidden="true"></div>
        <div class="flex min-h-dvh flex-col items-center justify-center gap-6 px-4 pb-[calc(1.5rem+env(safe-area-inset-bottom))] pt-[calc(1.5rem+var(--safe-top))]">
            <div class="flex w-full max-w-sm flex-col gap-6">
                <a href="{{ route('home') }}" class="flex items-center gap-3" wire:navigate>
                    <span class="flex size-12 items-center justify-center rounded-2xl bg-accent text-accent-ink">
                        <x-app-logo-icon class="size-7" />
                    </span>
                    <span class="text-xl font-semibold tracking-[-0.02em]">{{ config('app.name', 'Laravel') }}</span>
                </a>
                <div class="flex flex-col gap-6 rounded-card bg-surface p-6">
                    {{ $slot }}
                </div>
                @unless (request()->routeIs('install'))
                    <x-install-card />
                @endunless
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
