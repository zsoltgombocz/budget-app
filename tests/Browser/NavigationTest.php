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

it('puts the record button in the middle of the tab bar, so it never covers content', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('plan'))->on()->mobile();

    $layout = $page->script(<<<'JS'
        () => {
            const nav = document.querySelector('nav[aria-label]')
            const button = document.querySelector('[data-test="tab-entry"]')
            const items = [...nav.querySelector('div').children]
            return { inNav: nav.contains(button), middle: items.indexOf(button.parentElement) === 2, tabs: nav.querySelectorAll('a').length }
        }
    JS);

    expect($layout)->toBe(['inNav' => true, 'middle' => true, 'tabs' => 4]);
});

it('opens Settings from the Today header', function (): void {
    $this->actingAs(onboardedUser());

    visit(route('dashboard'))->on()->mobile()
        ->click('[data-test="open-settings"]')
        ->assertPathIs('/settings');
});
