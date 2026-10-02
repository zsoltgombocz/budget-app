<?php

it('marks the tab and shows the loader as soon as it is tapped', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('dashboard'))->on()->mobile();

    $state = $page->script(<<<'JS'
        async () => {
            const link = document.querySelector('nav a[href$="/terv"]')
            link.click()
            // The loader must be on synchronously, before the next page can arrive.
            const navigating = document.documentElement.classList.contains('navigating')
            await new Promise(resolve => requestAnimationFrame(resolve))
            return { active: link.getAttribute('aria-current'), navigating }
        }
    JS);

    expect($state)->toBe(['active' => 'page', 'navigating' => true]);
});

it('slides the record button away while scrolling down and brings it back on a clear scroll up', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('plan'))->on()->mobile()->resize(390, 500);

    $states = $page->script(<<<'JS'
        async () => {
            const wait = ms => new Promise(resolve => setTimeout(resolve, ms))
            const button = document.querySelector('[data-test="tab-entry"]')
            const visible = () => document.querySelector('[data-test="tab-entry"]').className.includes('translate-y-0')
            const max = document.documentElement.scrollHeight - window.innerHeight
            const scrollTo = async y => { window.scrollTo(0, y); await wait(150) }
            await wait(600)
            const atTop = visible()
            await scrollTo(max)
            const down = visible()
            await scrollTo(max - 12)
            const nudgedUp = visible()
            await scrollTo(max)

            // iOS rubber band at the bottom: scrollY overshoots and comes back to the end.
            const original = Object.getOwnPropertyDescriptor(window, 'scrollY')
            Object.defineProperty(window, 'scrollY', { configurable: true, get: () => max + 60 })
            window.dispatchEvent(new Event('scroll'))
            await wait(50)
            Object.defineProperty(window, 'scrollY', { configurable: true, get: () => max })
            window.dispatchEvent(new Event('scroll'))
            await wait(50)
            const afterBounce = visible()
            Object.defineProperty(window, 'scrollY', original)

            await scrollTo(max - 60)
            return { atTop, down, nudgedUp, afterBounce, up: visible() }
        }
    JS);

    expect($states)->toBe(['atTop' => true, 'down' => false, 'nudgedUp' => false, 'afterBounce' => false, 'up' => true]);
});
