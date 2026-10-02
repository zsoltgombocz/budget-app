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

it('hides the record button while scrolling down and brings it back on scroll up', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('plan'))->on()->mobile()->resize(390, 500);

    $states = $page->script(<<<'JS'
        async () => {
            const wait = ms => new Promise(resolve => setTimeout(resolve, ms))
            const button = document.querySelector('[data-test="tab-entry"]')
            const visible = () => ! button.className.includes('opacity-0')
            await wait(600)
            const atTop = visible()
            window.scrollTo(0, 400)
            await wait(300)
            const down = visible()
            window.scrollTo(0, 200)
            await wait(300)
            return { atTop, down, up: visible() }
        }
    JS);

    expect($states)->toBe(['atTop' => true, 'down' => false, 'up' => true]);
});
