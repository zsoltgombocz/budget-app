@php
    $tabs = [
        ['route' => 'dashboard', 'icon' => 'today', 'label' => __('Today')],
        ['route' => 'plan', 'icon' => 'list_alt', 'label' => __('Plan')],
        ['route' => 'pockets', 'icon' => 'savings', 'label' => __('Pockets')],
        ['route' => 'month', 'icon' => 'calendar_month', 'label' => __('Month')],
        ['route' => 'settings', 'icon' => 'settings', 'label' => __('Settings')],
    ];
@endphp

{{--
    Record button: floats above the tab bar, slides down behind it while scrolling down and
    comes back after a clear scroll up. The position is clamped to the real scroll range, so
    the iOS rubber-band bounce at the top or bottom never counts as scrolling.
--}}
<button type="button"
        x-data="{
            visible: true,
            anchorY: 0,
            position() {
                const max = Math.max(0, document.documentElement.scrollHeight - window.innerHeight)
                return Math.min(Math.max(window.scrollY, 0), max)
            },
            onScroll() {
                const y = this.position()
                if (y < 80) { this.visible = true; this.anchorY = y; return }
                if (this.visible) {
                    // Track the highest point since the button came back; hide after 8px down.
                    if (y < this.anchorY) this.anchorY = y
                    else if (y > this.anchorY + 8) { this.visible = false; this.anchorY = y }
                } else {
                    // Track the lowest point since it went away; show after a clear 24px up.
                    if (y > this.anchorY) this.anchorY = y
                    else if (y < this.anchorY - 24) { this.visible = true; this.anchorY = y }
                }
            },
        }"
        x-on:scroll.window.passive="onScroll()"
        x-init="anchorY = position(); document.addEventListener('livewire:navigated', () => { visible = true; anchorY = position() })"
        x-on:click="$dispatch('open-entry')"
        class="fixed right-5 bottom-[calc(82px+env(safe-area-inset-bottom)+16px)] z-30 flex size-[58px] items-center justify-center rounded-[20px] bg-accent text-accent-ink shadow-[0_10px_24px_color-mix(in_srgb,var(--app-accent)_32%,transparent)] transition-transform duration-300 ease-[cubic-bezier(0.2,0.7,0.2,1)] active:scale-95"
        :class="visible ? 'translate-y-0' : 'pointer-events-none translate-y-[calc(100%+120px)]'"
        aria-label="{{ __('Record spending') }}" data-test="tab-entry">
    <x-ui.icon name="add" :size="32" :weight="500" />
</button>

<nav class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-bg/90 pb-[env(safe-area-inset-bottom)] backdrop-blur-xl" aria-label="{{ __('Main navigation') }}">
    <div class="mx-auto grid h-[82px] max-w-lg grid-cols-5 items-start pt-2">
        @foreach ($tabs as $tab)
            <a href="{{ route($tab['route']) }}" wire:navigate
               x-data="{
                    active: false,
                    path: @js(parse_url(route($tab['route']), PHP_URL_PATH)),
                    matches(pathname) { return pathname === this.path || pathname.startsWith(this.path + '/') },
               }"
               x-init="active = matches(location.pathname); document.addEventListener('livewire:navigated', () => active = matches(location.pathname)); window.addEventListener('tab-selected', e => active = matches(e.detail))"
               x-on:click="if (location.pathname !== path) document.documentElement.classList.add('navigating'); window.dispatchEvent(new CustomEvent('tab-selected', { detail: path }))"
               class="flex flex-col items-center gap-[3px] text-[11px]"
               :class="active ? 'font-semibold text-ink' : 'font-medium text-zinc-500'"
               :aria-current="active ? 'page' : null">
                <span class="ms" style="font-size:24px;width:24px;height:24px" :class="active && 'ms-fill'" aria-hidden="true">{{ $tab['icon'] }}</span>
                {{ $tab['label'] }}
            </a>
        @endforeach
    </div>
</nav>
