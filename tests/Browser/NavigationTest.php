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

it('slides the record button away on any scroll down and ignores the bounce after a fling down', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('plan'))->on()->mobile()->resize(390, 500);

    $states = $page->script(<<<'JS'
        async () => {
            const wait = ms => new Promise(resolve => setTimeout(resolve, ms))
            const visible = () => document.querySelector('[data-test="tab-entry"]').className.includes('translate-y-0')
            const touch = (type, y) => {
                const point = new Touch({ identifier: 1, target: document.body, clientX: 200, clientY: y })
                window.dispatchEvent(new TouchEvent(type, { touches: type === 'touchend' ? [] : [point], bubbles: true }))
            }
            const scrollTo = async y => { window.scrollTo(0, y); await wait(60) }
            const max = document.documentElement.scrollHeight - window.innerHeight
            await wait(600)
            const atTop = visible()

            // A slow scroll down hides it, no fling needed.
            await scrollTo(100)
            await scrollTo(120)
            const slowDown = visible()

            // Fling down to the end (finger moves up), then the rubber band springs back.
            touch('touchstart', 400)
            touch('touchmove', 380)
            touch('touchmove', 300)
            touch('touchend', 0)
            await scrollTo(max)
            await scrollTo(max - 40)
            await scrollTo(max)
            const afterBounce = visible()

            // Dragging the page down (finger moves down) scrolls up and brings it back.
            touch('touchstart', 300)
            touch('touchmove', 320)
            touch('touchmove', 360)
            await scrollTo(max - 60)
            touch('touchend', 0)
            return { atTop, slowDown, afterBounce, up: visible() }
        }
    JS);

    expect($states)->toBe(['atTop' => true, 'slowDown' => false, 'afterBounce' => false, 'up' => true]);
});

it('slides behind the tab bar', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('plan'))->on()->mobile();

    $layers = $page->script("() => ({ button: getComputedStyle(document.querySelector('[data-test=\"tab-entry\"]')).zIndex, nav: getComputedStyle(document.querySelector('nav[aria-label]')).zIndex })");

    expect((int) $layers['button'])->toBeLessThan((int) $layers['nav']);
});

it('has no record button on the settings pages', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('settings'))->on()->mobile();

    $visible = $page->script("async () => { await new Promise(r => setTimeout(r, 600)); return document.querySelector('[data-test=\"tab-entry\"]').className.includes('translate-y-0') }");

    expect($visible)->toBeFalse();
});
