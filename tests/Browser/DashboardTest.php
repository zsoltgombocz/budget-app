<?php

use App\Models\BudgetLine;
use App\Models\Category;

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

it('ticks a fixed item on Today, keeps it in place and undoes it from the toast', function (): void {
    $user = onboardedUser();
    $phone = Category::factory()->for($user)->create(['name' => 'Phone', 'type' => 'fixed']);
    BudgetLine::factory()->for($user)->for($phone)->create(['amount' => 12_990]);
    $this->actingAs($user);

    $order = "() => [...document.querySelectorAll('[data-test=fixed-item]')].map(b => b.textContent.includes('Rent') ? 'Rent' : 'Phone').join(',')";

    $page = visit(route('dashboard'))->on()->mobile()->resize(390, 844);
    $before = $page->script($order);
    expect(explode(',', (string) $before))->toHaveCount(2);

    $page->click('[data-test="fixed-item"] >> nth=0')
        ->waitForText('Marked as done.')
        ->assertSee('Undo');
    // Paid items sort last on a fresh page; the ticked first item stays where it was.
    expect($page->script($order))->toBe($before)
        ->and($page->script("() => document.querySelector('[data-test=fixed-item]').getAttribute('aria-checked')"))->toBe('true');

    $page->click('Undo')
        ->assertScript("document.querySelector('[data-test=fixed-item]').getAttribute('aria-checked')", 'false')
        ->assertNoJavaScriptErrors();
});
