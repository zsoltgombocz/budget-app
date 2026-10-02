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
    Record button: floats above the tab bar. On a phone it follows the finger: dragging the page
    up (scrolling down) slides it away, a clear drag down brings it back. The iOS rubber-band
    bounce happens without a finger, so it never brings the button back. Without touch (mouse,
    trackpad) the scroll position decides. Hidden on the settings pages.
--}}
<button type="button"
        x-data="{
            visible: true,
            allowed: true,
            touchY: null,
            touched: false,
            anchorY: 0,
            hiddenOn: @js(array_map(fn (string $name): string => (string) parse_url(route($name), PHP_URL_PATH), ['settings', 'install', 'changelog'])),
            syncPage() {
                this.allowed = ! this.hiddenOn.some(path => location.pathname === path || location.pathname.startsWith(path + '/'))
                this.visible = true
                this.anchorY = window.scrollY
            },
            nearTop() { return window.scrollY < 80 },
            onTouchMove(y) {
                if (this.touchY === null) { this.touchY = y; return }
                const moved = y - this.touchY
                if (moved < -8) { if (! this.nearTop()) this.visible = false; this.touchY = y }
                else if (moved > 24) { this.visible = true; this.touchY = y }
            },
            onScroll() {
                if (this.nearTop()) { this.visible = true; return }
                if (this.touched) return
                const y = window.scrollY
                if (this.visible) {
                    if (y < this.anchorY) this.anchorY = y
                    else if (y > this.anchorY + 8) { this.visible = false; this.anchorY = y }
                } else {
                    if (y > this.anchorY) this.anchorY = y
                    else if (y < this.anchorY - 24) { this.visible = true; this.anchorY = y }
                }
            },
        }"
        x-on:touchstart.window.passive="touched = true; touchY = $event.touches[0]?.clientY ?? null"
        x-on:touchmove.window.passive="onTouchMove($event.touches[0]?.clientY ?? 0)"
        x-on:touchend.window.passive="touchY = null"
        x-on:scroll.window.passive="onScroll()"
        x-init="syncPage(); document.addEventListener('livewire:navigated', () => syncPage())"
        x-on:click="$dispatch('open-entry')"
        class="fixed right-5 bottom-[calc(82px+env(safe-area-inset-bottom)+16px)] z-40 flex size-[58px] items-center justify-center rounded-[20px] bg-accent text-accent-ink shadow-[0_10px_24px_color-mix(in_srgb,var(--app-accent)_32%,transparent)] transition-transform active:scale-95"
        :class="visible && allowed ? 'translate-y-0 duration-300 ease-out' : 'pointer-events-none translate-y-[calc(100%_+_160px)] duration-300 ease-in'"
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
