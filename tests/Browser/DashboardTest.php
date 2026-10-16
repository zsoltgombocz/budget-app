<?php

use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\PeriodLineStatus;

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
        ->assertSee('3. The two numbers')
        ->assertSee('The big number: what would be left if the month ended today.')
        ->click('[data-test="intro-next"]')
        ->assertSee('4. Closing the month')
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
        ->waitForText('Paid.')
        ->assertSee('Undo');
    // Paid items sort last on a fresh page; the ticked first item stays where it was.
    expect($page->script($order))->toBe($before)
        ->and($page->script("() => document.querySelector('[data-test=fixed-item]').getAttribute('aria-checked')"))->toBe('true');

    $page->click('Undo')
        ->assertScript("document.querySelector('[data-test=fixed-item]').getAttribute('aria-checked')", 'false')
        ->assertNoJavaScriptErrors();
});

it('skips a due fixed item for this month on Today and undoes it, and sets it back from the list', function (): void {
    $user = onboardedUser();
    $category = Category::factory()->for($user)->create(['name' => 'PS Plus', 'type' => 'fixed']);
    $line = BudgetLine::factory()->for($user)->for($category)->create(['amount' => 4_990, 'due_day' => 1]);
    $this->actingAs($user);

    $page = visit(route('dashboard'))->on()->mobile()->resize(390, 844);

    $page->assertSeeIn('[data-test="due-summary"]', '1 fixed item due')
        ->assertMissing('[data-test="due-item"]:visible')
        ->click('[data-test="due-summary"]')
        ->assertVisible('[data-test="due-item"]')
        ->click('[data-test="skip-due"]')
        ->waitForText('Skipped this month.')
        ->assertSee(money(4_990).' more is left.')
        ->assertMissing('[data-test="due-summary"]')
        ->assertSee('skipped – counted as')
        ->click('Undo')
        ->assertVisible('[data-test="due-summary"]');

    $page->click('[data-test="fixed-item-row"][data-status="open"] [data-test="fixed-item-options"] >> nth=0')
        ->assertVisible('[data-test="fixed-item-sheet"]')
        ->click('[data-test="skip-line"]')
        ->waitForText('Skipped this month.')
        ->click('[data-test="fixed-item-row"][data-status="skipped"] [data-test="fixed-item"]')
        ->assertVisible('[data-test="due-again"]')
        ->assertMissing('[data-test="paid-after-all"]')
        ->click('[data-test="due-again"]')
        ->waitForText('It comes up after all.')
        ->assertVisible('[data-test="due-summary"]')
        ->assertNoJavaScriptErrors();

    expect(PeriodLineStatus::query()->where('budget_line_id', $line->id)->whereNotNull('skipped_on')->exists())->toBeFalse();
});

it('shows the leftover, the daily figure with its record button and the due row in that order, on a 320 px phone too', function (): void {
    $user = onboardedUser();
    BudgetLine::query()->whereRelation('category', 'name', 'Rent')->firstOrFail()->update(['due_day' => 1]);
    $this->actingAs($user);

    $page = visit(route('dashboard'))->on()->mobile()->resize(320, 568);

    $layout = $page->script(<<<'JS'
        () => {
            const top = selector => document.querySelector(selector).getBoundingClientRect().top
            const tabBar = top('nav[aria-label="Main navigation"]')
            return {
                order: ['[data-test="expected-leftover"]', '[data-test="daily-allowance"]', '[data-test="due-items"]', '[data-test="categories"]', '[data-test="fixed-items"]']
                    .map(top).every((value, index, all) => index === 0 || value > all[index - 1]),
                recordAboveTabBar: document.querySelector('[data-test="daily-record"]').getBoundingClientRect().bottom <= tabBar,
                noSideScroll: document.documentElement.scrollWidth <= window.innerWidth,
            }
        }
    JS);

    expect($layout)->toBe(['order' => true, 'recordAboveTabBar' => true, 'noSideScroll' => true]);
    $page->assertNoJavaScriptErrors();
});

it('leaves room under the last row so the record button covers nothing at the end of the page', function (string $route, int $width, int $height): void {
    $user = onboardedUser();
    foreach (['Phone', 'Internet', 'Gym', 'Insurance', 'Streaming'] as $name) {
        $category = Category::factory()->for($user)->create(['name' => $name, 'type' => 'fixed']);
        BudgetLine::factory()->for($user)->for($category)->create(['amount' => 5_000]);
    }
    $this->actingAs($user);

    $page = visit(route($route))->on()->mobile()->resize($width, $height);

    $clear = $page->script(<<<'JS'
        async () => {
            window.scrollTo(0, document.documentElement.scrollHeight)
            await new Promise(resolve => setTimeout(resolve, 100))
            const button = document.querySelector('[data-test="tab-entry"]')
            const content = [...document.querySelectorAll('main .page-enter *')].filter(el => el.offsetParent !== null && el.getBoundingClientRect().height > 0)
            const lastBottom = Math.max(...content.map(el => el.getBoundingClientRect().bottom))
            // offsetTop ignores the slide-away transform: where the button sits when it shows.
            return lastBottom <= button.offsetTop
        }
    JS);

    expect($clear)->toBeTrue();
})->with([
    'Today 320' => ['dashboard', 320, 568],
    'Today iPhone 15' => ['dashboard', 393, 852],
    'Month 320' => ['month', 320, 568],
    'Plan 320' => ['plan', 320, 568],
    'Pockets 320' => ['pockets', 320, 568],
]);
