@props(['title' => null, 'tabs' => true])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-dvh bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        <div class="mx-auto flex min-h-dvh w-full max-w-lg flex-col">
            <header class="sticky top-0 z-20 flex items-center gap-3 border-b border-zinc-200 bg-zinc-50/90 px-4 pb-3 pt-[max(0.75rem,env(safe-area-inset-top))] backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/90">
                <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2" aria-label="{{ config('app.name') }}">
                    <x-app-logo-icon class="size-7 fill-current text-zinc-900 dark:text-white" />
                </a>

                <flux:heading size="lg" class="truncate">{{ filled($title) ? __($title) : "" }}</flux:heading>

                <flux:spacer />

                <flux:dropdown position="bottom" align="end">
                    <flux:profile :initials="auth()->user()->initials()" icon-trailing="chevron-down" />

                    <flux:menu>
                        <div class="px-2 py-1.5 text-sm">
                            <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                            <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                        </div>

                        <flux:menu.separator />

                        <flux:menu.item :href="route('budget.edit')" icon="adjustments-horizontal" wire:navigate>
                            {{ __('Budget settings') }}
                        </flux:menu.item>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>

                        <flux:menu.separator />

                        <form method="POST" action="{{ route('logout') }}" class="w-full">
                            @csrf
                            <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer" data-test="logout-button">
                                {{ __('Log out') }}
                            </flux:menu.item>
                        </form>
                    </flux:menu>
                </flux:dropdown>
            </header>

            <main @class(['flex-1 px-4 pt-4', 'pb-[calc(6rem+env(safe-area-inset-bottom))]' => $tabs, 'pb-[calc(1.5rem+env(safe-area-inset-bottom))]' => ! $tabs])>
                {{ $slot }}
            </main>

            @if ($tabs)
                <x-tab-bar />
            @endif
        </div>

        @persist('toast')
            <flux:toast.group position="top end">
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
