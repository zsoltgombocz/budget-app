<?php

it('shows the expected leftover, the daily budget and the first budget without scrolling at 375 px', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('dashboard'))->on()->mobile()->resize(375, 667);

    $page->assertSee('Leftover as things stand')->assertNoJavaScriptErrors();

    $fits = $page->script(<<<'JS'
        () => {
            const tabBar = document.querySelector('nav[aria-label="Main navigation"]').getBoundingClientRect().top;
            const visible = selector => document.querySelector(selector).getBoundingClientRect().bottom <= tabBar;
            return visible('[data-test="expected-leftover"]')
                && visible('[data-test="daily-allowance"]')
                && document.documentElement.scrollWidth <= window.innerWidth;
        }
    JS);

    expect($fits)->toBeTrue();
});
