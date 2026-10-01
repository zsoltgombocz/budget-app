@php
    $tabs = [
        ['route' => 'dashboard', 'icon' => 'today', 'label' => __('Today')],
        ['route' => 'plan', 'icon' => 'list_alt', 'label' => __('Plan')],
        null,
        ['route' => 'pockets', 'icon' => 'savings', 'label' => __('Pockets')],
        ['route' => 'month', 'icon' => 'calendar_month', 'label' => __('Month')],
    ];
@endphp

<nav class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-bg/90 pb-[env(safe-area-inset-bottom)] backdrop-blur-xl" aria-label="{{ __('Main navigation') }}">
    <div class="mx-auto grid h-[82px] max-w-lg grid-cols-5 items-start pt-2">
        @foreach ($tabs as $tab)
            @if ($tab === null)
                <div class="flex justify-center">
                    <button type="button" x-data x-on:click="$dispatch('open-entry')"
                            class="-mt-6 flex size-[58px] items-center justify-center rounded-[20px] bg-accent text-accent-ink shadow-[0_10px_24px_rgba(75,216,138,0.32),0_0_0_6px_var(--app-bg)] transition active:scale-95"
                            aria-label="{{ __('Record spending') }}" data-test="tab-entry">
                        <x-ui.icon name="add" :size="32" :weight="500" />
                    </button>
                </div>
            @else
                <a href="{{ route($tab['route']) }}" wire:navigate
                   x-data="{ active: false }"
                   x-init="const sync = () => active = location.pathname === @js(parse_url(route($tab['route']), PHP_URL_PATH)) || location.pathname.startsWith(@js(parse_url(route($tab['route']), PHP_URL_PATH)) + '/'); sync(); document.addEventListener('livewire:navigated', sync)"
                   class="flex flex-col items-center gap-[3px] text-[11px]"
                   :class="active ? 'font-semibold text-ink' : 'font-medium text-zinc-500'"
                   :aria-current="active ? 'page' : null">
                    <span class="ms" style="font-size:24px;width:24px;height:24px" :class="active && 'ms-fill'" aria-hidden="true">{{ $tab['icon'] }}</span>
                    {{ $tab['label'] }}
                </a>
            @endif
        @endforeach
    </div>
</nav>
