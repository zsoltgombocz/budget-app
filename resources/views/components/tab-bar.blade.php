@php
    $tabs = [
        ['route' => 'dashboard', 'icon' => 'home', 'label' => __('Today')],
        ['route' => 'plan', 'icon' => 'clipboard-document-list', 'label' => __('Plan')],
        null,
        ['route' => 'pockets', 'icon' => 'wallet', 'label' => __('Pockets')],
        ['route' => 'month', 'icon' => 'calendar-days', 'label' => __('Month')],
    ];
@endphp

<nav class="fixed inset-x-0 bottom-0 z-30 border-t border-zinc-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/95" aria-label="{{ __('Main navigation') }}">
    <div class="mx-auto grid h-16 max-w-lg grid-cols-5 items-center">
        @foreach ($tabs as $tab)
            @if ($tab === null)
                <div class="flex justify-center">
                    <a href="{{ route('entry') }}" wire:navigate
                       class="-mt-6 flex size-14 items-center justify-center rounded-full bg-emerald-600 text-white shadow-lg ring-4 ring-zinc-50 transition active:scale-95 dark:ring-zinc-900"
                       aria-label="{{ __('Record spending') }}" data-test="tab-entry">
                        <flux:icon.plus class="size-7" />
                    </a>
                </div>
            @else
                @php($active = request()->routeIs($tab['route']))
                <a href="{{ Route::has($tab['route']) ? route($tab['route']) : '#' }}" wire:navigate
                   @class([
                       'flex flex-col items-center gap-0.5 text-[11px] font-medium',
                       'text-emerald-600 dark:text-emerald-400' => $active,
                       'text-zinc-500 dark:text-zinc-400' => ! $active,
                   ])
                   @if ($active) aria-current="page" @endif>
                    <flux:icon :icon="$tab['icon']" :variant="$active ? 'solid' : 'outline'" class="size-6" />
                    {{ $tab['label'] }}
                </a>
            @endif
        @endforeach
    </div>
</nav>
