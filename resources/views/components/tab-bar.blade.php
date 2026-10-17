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
    Record button: floats above the tab bar and slides down behind it while scrolling down.
    Scrolling up brings it back, but only when the last finger drag pointed up the page (or
    there is no touch, e.g. a mouse): the iOS rubber-band bounce after a hard fling down scrolls
    up on its own and must not count. Hidden on the settings pages (and their sub-pages), and
    while the page has an element marked data-hides-record-button (a closed month, Today's open due list, the plan's
    edit mode): there is nothing to record into there. While an element marked
    data-record-button-twin (Today's own "Record" button) is on screen it stays away too: two
    buttons doing the same thing must never sit side by side. It also steps aside while a toast shows
    (app-toast-visibility from x-toast-host): the toast sits right above the tab bar, where the
    button would cover it. Sizes come from --tabbar-h, --fab-size and --fab-gap (app.css).
--}}
<button type="button"
        x-data="{
            visible: true,
            anchorY: 0,
            touchY: null,
            lastDrag: null,
            hiddenOn: @js(array_map(fn (string $name): string => (string) parse_url(route($name), PHP_URL_PATH), ['settings', 'install', 'changelog'])),
            pageAllows: true,
            marked: false,
            twinInView: false,
            twin: null,
            twinObserver: null,
            toastOpen: false,
            get allowed() { return this.pageAllows && ! this.marked && ! this.twinInView },
            syncPage() {
                const path = location.pathname.replace(/\/+$/, '') || '/'
                this.pageAllows = ! this.hiddenOn.some(hidden => path === hidden || path.startsWith(hidden + '/'))
                this.syncMarker()
                this.visible = true
                this.anchorY = window.scrollY
            },
            syncMarker() {
                this.marked = document.querySelector('[data-hides-record-button]') !== null
                this.syncTwin()
            },
            syncTwin() {
                const twin = document.querySelector('[data-record-button-twin]')
                if (twin === this.twin) return
                this.twinObserver?.disconnect()
                this.twin = twin
                if (! twin) { this.twinInView = false; return }
                // Measured right away too, so the + does not slide in on load before the observer first reports.
                const box = twin.getBoundingClientRect()
                this.twinInView = box.height > 0 && box.bottom > 0 && box.top < window.innerHeight - 100
                // The strip above the tab bar does not count: a button behind the bar is not on screen.
                this.twinObserver ??= new IntersectionObserver(entries => { this.twinInView = entries.at(-1).isIntersecting }, { rootMargin: '0px 0px -100px 0px' })
                this.twinObserver.observe(twin)
            },
            onTouchMove(y) {
                if (this.touchY !== null && Math.abs(y - this.touchY) > 4) this.lastDrag = y > this.touchY ? 'up' : 'down'
                this.touchY = y
            },
            onScroll() {
                const y = window.scrollY
                if (y < 80) { this.visible = true; this.anchorY = y; return }
                if (this.visible) {
                    if (y < this.anchorY) this.anchorY = y
                    else if (y > this.anchorY + 8) { this.visible = false; this.anchorY = y }
                } else if (y > this.anchorY) {
                    this.anchorY = y
                } else if (y < this.anchorY - 24 && this.lastDrag !== 'down') {
                    this.visible = true
                    this.anchorY = y
                }
            },
        }"
        x-on:touchstart.window.passive="touchY = $event.touches[0]?.clientY ?? null"
        x-on:touchmove.window.passive="onTouchMove($event.touches[0]?.clientY ?? 0)"
        x-on:scroll.window.passive="onScroll()"
        x-on:app-toast-visibility.window="toastOpen = $event.detail"
        x-init="syncPage(); document.addEventListener('livewire:navigated', () => syncPage()); new MutationObserver(() => syncMarker()).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['data-hides-record-button'] })"
        x-on:click="$dispatch('open-entry')"
        class="fixed right-5 bottom-[calc(var(--tabbar-h)+env(safe-area-inset-bottom)+var(--fab-gap))] z-20 flex size-(--fab-size) items-center justify-center rounded-[20px] bg-accent text-accent-ink shadow-[0_10px_24px_color-mix(in_srgb,var(--app-accent)_32%,transparent)] transition-transform active:scale-95"
        :class="visible && allowed && ! toastOpen ? 'translate-y-0 duration-300 ease-out' : 'pointer-events-none translate-y-[calc(100%_+_160px)] duration-[400ms] ease-in'"
        :aria-hidden="allowed ? null : 'true'" :tabindex="allowed ? null : '-1'"
        aria-label="{{ __('Record spending') }}" data-test="tab-entry">
    <x-ui.icon name="add" :size="32" :weight="500" />
</button>

<nav class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-bg pb-[env(safe-area-inset-bottom)]" aria-label="{{ __('Main navigation') }}">
    <div data-tour="tabs" class="mx-auto grid h-(--tabbar-h) max-w-lg grid-cols-5 items-start pt-2">
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
               :class="active ? 'font-semibold text-ink' : 'font-medium text-muted'"
               :aria-current="active ? 'page' : null">
                <span class="ms" style="font-size:24px;width:24px;height:24px" :class="active && 'ms-fill'" aria-hidden="true">{{ $tab['icon'] }}</span>
                {{ $tab['label'] }}
            </a>
        @endforeach
    </div>
</nav>
