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

it('walks through the intro after onboarding and explains the daily figure', function (): void {
    $user = onboardedUser(['intro_seen_at' => null]);
    $this->actingAs($user);

    $page = visit(route('dashboard'))->on()->mobile();

    $page->assertVisible('[data-test="how-it-works"]')
        ->assertSee('1. The plan')
        ->click('[data-test="intro-next"]')
        ->assertSee('2. Recording spending')
        ->click('[data-test="intro-next"]')
        ->assertSee('3. Closing the period')
        ->click('[data-test="intro-done"]')
        ->assertMissing('[data-test="how-it-works"]:visible')
        ->click('[data-test="explain-daily"]')
        ->assertVisible('[data-test="explainer-daily"]')
        ->assertSee('Days left, today included')
        ->assertNoJavaScriptErrors();

    expect($user->settings()->refresh()->intro_seen_at)->not->toBeNull();
});
