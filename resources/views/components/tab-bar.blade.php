@php
    $left = [
        ['route' => 'dashboard', 'icon' => 'today', 'label' => __('Today')],
        ['route' => 'plan', 'icon' => 'list_alt', 'label' => __('Plan')],
    ];
    $right = [
        ['route' => 'pockets', 'icon' => 'savings', 'label' => __('Pockets')],
        ['route' => 'month', 'icon' => 'calendar_month', 'label' => __('Month')],
    ];
@endphp

{{--
    Main navigation (design: four tabs with the record button in the middle). The bar is solid so
    nothing shows through its labels; the record button sits in the bar, so it never covers content.
--}}
<nav class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-bg pb-[env(safe-area-inset-bottom)]" aria-label="{{ __('Main navigation') }}">
    <div class="mx-auto grid h-[76px] max-w-lg grid-cols-5 items-start pt-2">
        @foreach ([$left, $right] as $index => $group)
            @foreach ($group as $tab)
                <a href="{{ route($tab['route']) }}" wire:navigate
                   x-data="{
                        active: false,
                        path: @js(parse_url(route($tab['route']), PHP_URL_PATH)),
                        matches(pathname) { return pathname === this.path || pathname.startsWith(this.path + '/') },
                   }"
                   x-init="active = matches(location.pathname); document.addEventListener('livewire:navigated', () => active = matches(location.pathname)); window.addEventListener('tab-selected', e => active = matches(e.detail))"
                   x-on:click="if (location.pathname !== path) document.documentElement.classList.add('navigating'); window.dispatchEvent(new CustomEvent('tab-selected', { detail: path }))"
                   class="flex min-h-[52px] flex-col items-center justify-center gap-[3px] rounded-xl text-[11px] focus-visible:outline-2 focus-visible:outline-accent"
                   :class="active ? 'font-semibold text-ink' : 'font-medium text-muted'"
                   :aria-current="active ? 'page' : null">
                    <span class="ms" style="font-size:24px;width:24px;height:24px" :class="active && 'ms-fill'" aria-hidden="true">{{ $tab['icon'] }}</span>
                    {{ $tab['label'] }}
                </a>
            @endforeach

            @if ($index === 0)
                <div class="flex justify-center">
                    <button type="button"
                            x-data
                            x-on:click="$dispatch('open-entry')"
                            class="-mt-5 flex size-[58px] items-center justify-center rounded-[20px] bg-accent text-accent-ink shadow-[0_10px_24px_color-mix(in_srgb,var(--app-accent)_32%,transparent)] transition-transform focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent active:scale-95"
                            aria-label="{{ __('Record spending') }}" data-test="tab-entry">
                        <x-ui.icon name="add" :size="32" :weight="500" />
                    </button>
                </div>
            @endif
        @endforeach
    </div>
</nav>
