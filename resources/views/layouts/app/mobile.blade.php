@props(['title' => null, 'tabs' => true])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-dvh bg-bg font-sans text-ink antialiased">
        <x-splash />
        <div id="status-shield" aria-hidden="true"></div>
        <div id="nav-progress" aria-hidden="true"></div>

        <div class="mx-auto min-h-dvh w-full max-w-lg">
            <main @class([
                'pt-[var(--safe-top)]',
                'pb-[calc(118px+env(safe-area-inset-bottom))]' => $tabs,
                'pb-[env(safe-area-inset-bottom)]' => ! $tabs,
            ])>
                <div class="page-enter">
                    {{ $slot }}
                </div>
            </main>
        </div>

        @if ($tabs)
            @persist('tab-bar')
                <x-tab-bar />
            @endpersist

            @persist('entry-sheet')
                <livewire:entry-sheet />
            @endpersist
        @endif

        @auth
            @unless (request()->routeIs('changelog'))
                <x-new-version />
            @endunless
        @endauth

        @persist('app-toast')
            <x-toast-host :tabs="$tabs" />
        @endpersist

        @persist('toast')
            <flux:toast.group position="top end">
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
