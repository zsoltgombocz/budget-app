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

it('slides the record button away when the finger scrolls down and brings it back on a clear drag up, ignoring the bounce', function (): void {
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
            const max = document.documentElement.scrollHeight - window.innerHeight
            await wait(600)
            const atTop = visible()

            // Finger moves up: the page scrolls down.
            touch('touchstart', 400)
            window.scrollTo(0, max)
            touch('touchmove', 370)
            touch('touchend', 0)
            await wait(50)
            const down = visible()

            // The rubber band springs back with no finger on the screen.
            window.scrollTo(0, max - 40)
            await wait(50)
            window.scrollTo(0, max)
            await wait(50)
            const afterBounce = visible()

            // A small wobble of the finger is not a scroll up, a clear drag down is.
            touch('touchstart', 300)
            touch('touchmove', 312)
            await wait(50)
            const nudged = visible()
            touch('touchmove', 345)
            await wait(50)
            return { atTop, down, afterBounce, nudged, up: visible() }
        }
    JS);

    expect($states)->toBe(['atTop' => true, 'down' => false, 'afterBounce' => false, 'nudged' => false, 'up' => true]);
});

it('has no record button on the settings pages', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('settings'))->on()->mobile();

    $visible = $page->script("async () => { await new Promise(r => setTimeout(r, 600)); return document.querySelector('[data-test=\"tab-entry\"]').className.includes('translate-y-0') }");

    expect($visible)->toBeFalse();
});
