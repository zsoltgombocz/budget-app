@php
    $tabs = [
        ['route' => 'dashboard', 'icon' => 'light_mode', 'label' => __('Today')],
        ['route' => 'month', 'icon' => 'calendar_month', 'label' => __('Month')],
        ['route' => 'companion', 'icon' => null, 'label' => auth()->user()?->settings()->companion_mode === 'off' ? __('Progress') : __('Morzsa')],
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
    button would cover it. During the "How it works" tour (html[data-touring]) it stays under the tour's
    dim like the rest of the page, toned down further so its bright green does not stand out.
    It sits in the tab bar's row, right of the pill. Sizes come from --tabbar-h and --fab-size (app.css).
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
        class="fixed right-3 bottom-[calc(12px+env(safe-area-inset-bottom))] z-40 flex size-(--fab-size) items-center justify-center rounded-full bg-accent text-accent-ink shadow-e2 transition-transform active:scale-95 [html[data-touring]_&]:opacity-50 [html[data-touring]_&]:shadow-none"
        :class="visible && allowed && ! toastOpen ? 'translate-y-0 duration-300 ease-out' : 'pointer-events-none translate-y-[calc(100%_+_160px)] duration-[400ms] ease-in'"
        :aria-hidden="allowed ? null : 'true'" :tabindex="allowed ? null : '-1'"
        aria-label="{{ __('Record spending') }}" data-test="tab-entry">
    <x-ui.icon name="add" :size="32" :weight="500" />
</button>

{{-- 2.0 navigation: a floating glass pill with four tabs; the record button sits right of it. --}}
<nav class="pointer-events-none fixed inset-x-0 bottom-0 z-30 pb-[calc(12px+env(safe-area-inset-bottom))]" aria-label="{{ __('Main navigation') }}">
    <div class="mx-auto flex max-w-lg px-3">
        <div class="pointer-events-auto mr-[calc(var(--fab-size)+8px)] flex h-16 flex-1 items-center justify-around rounded-full bg-glass px-1 shadow-[var(--app-e2),var(--app-gedge)] backdrop-blur-xl">
            @foreach ($tabs as $tab)
                <a href="{{ route($tab['route']) }}" wire:navigate
                   x-data="{
                        active: false,
                        path: @js(parse_url(route($tab['route']), PHP_URL_PATH)),
                        matches(pathname) { return pathname === this.path || pathname.startsWith(this.path + '/') },
                   }"
                   x-init="active = matches(location.pathname); document.addEventListener('livewire:navigated', () => active = matches(location.pathname)); window.addEventListener('tab-selected', e => active = matches(e.detail))"
                   x-on:click="if (location.pathname !== path) document.documentElement.classList.add('navigating'); window.dispatchEvent(new CustomEvent('tab-selected', { detail: path }))"
                   class="flex h-14 min-w-0 flex-1 flex-col items-center justify-center gap-0.5 text-xs leading-4 font-semibold max-[359px]:text-[0px]"
                   :class="active ? 'text-accent-strong max-[359px]:text-[11px]' : 'text-muted'"
                   :aria-current="active ? 'page' : null"
                   aria-label="{{ $tab['label'] }}">
                    <span class="flex h-8 w-14 items-center justify-center rounded-full max-[359px]:w-11" :class="active && 'bg-accent-tint'">
                        @if ($tab['icon'])
                            <span class="ms" style="font-size:24px;width:24px;height:24px" :class="active && 'ms-fill'" aria-hidden="true">{{ $tab['icon'] }}</span>
                        @else
                            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="7.4" cy="6.8" r="2.2"/><circle cx="16.6" cy="6.8" r="2.2"/><path d="M12 6.5c4.4 0 7.5 3.6 7.5 7.6 0 3.6-3 5.9-7.5 5.9s-7.5-2.3-7.5-5.9c0-4 3.1-7.6 7.5-7.6z"/><path d="M9.6 12.6v.4M14.4 12.6v.4"/></svg>
                        @endif
                    </span>
                    {{ $tab['label'] }}
                </a>
            @endforeach
        </div>
    </div>
</nav>
