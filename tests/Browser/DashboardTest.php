<?php

use App\Enums\AccountType;
use App\Enums\Currency;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\PeriodClose;
use App\Models\PeriodLineStatus;
use App\Models\Transaction;
use App\Services\PeriodService;
use Illuminate\Support\Sleep;

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

/**
 * Measures the tour step on screen once it has settled (the card faded in, the spotlight
 * standing still): the spotlight against the lit element (a tall one is lit as far as the card
 * leaves it free), the card on screen and holding focus. Polls instead of relying on timing.
 */
function measureTourStep(string $theme): string
{
    return <<<JS
        async () => {
            const wait = ms => new Promise(resolve => setTimeout(resolve, ms))
            const until = async (check, tries = 80) => { for (let i = 0; i < tries && ! check(); i++) await wait(40); return check() }
            document.documentElement.classList.toggle('dark', '{$theme}' === 'dark')
            const tip = () => document.querySelector('[data-test="tour-tooltip"]')
            const tour = () => { const root = document.querySelector('[x-data^="appTour"]'); return root && window.Alpine?.\$data(root) }
            const box = selector => document.querySelector(selector).getBoundingClientRect()
            await until(() => tour()?.tourActive && tour().tourPlaced && tour().tourStep.id !== window.tourPrevious && getComputedStyle(tip()).opacity === '1')
            let last = ''
            await until(() => { const now = JSON.stringify(box('[data-test="tour-spotlight"]')); const still = now === last; last = now; return still })

            const id = tour().tourStep.id
            const rects = [...document.querySelectorAll('[data-tour~="' + id + '"]')].map(element => element.getBoundingClientRect()).filter(r => r.height > 0)
            const want = {
                top: Math.max(0, Math.min(...rects.map(r => r.top)) - 6),
                bottom: Math.min(window.innerHeight, Math.max(...rects.map(r => r.bottom)) + 6),
                left: Math.max(4, Math.min(...rects.map(r => r.left)) - 6),
                right: Math.min(window.innerWidth - 4, Math.max(...rects.map(r => r.right)) + 6),
            }
            const spot = box('[data-test="tour-spotlight"]')
            const card = tip().getBoundingClientRect()
            const near = (a, b) => Math.abs(a - b) <= 2.5
            const whole = near(spot.top, want.top) && near(spot.bottom, want.bottom)

            return {
                id,
                last: tour().tourIsLast,
                spotMatches: near(spot.left, want.left) && near(spot.right, want.right) && (whole || (spot.top >= want.top - 2.5 && spot.bottom <= want.bottom + 2.5 && spot.height >= 20)),
                whole,
                cardOnScreen: card.top >= -0.5 && card.left >= -0.5 && card.bottom <= window.innerHeight + 0.5 && card.right <= window.innerWidth + 0.5,
                cardClearOfSpot: card.bottom <= spot.top + 0.5 || card.top >= spot.bottom - 0.5,
                focusInCard: tip().contains(document.activeElement),
            }
        }
    JS;
}

/** Presses a button of the tour card, remembering the step it was on. */
function pressTour(string $button): string
{
    return <<<JS
        () => {
            window.tourPrevious = window.Alpine.\$data(document.querySelector('[x-data^="appTour"]')).tourStep.id
            document.querySelector('[data-test="tour-{$button}"]').click()
        }
    JS;
}

it('walks the Today tour after onboarding: the spotlight sits on each element, the card stays on screen, Escape ends it', function (string $theme, int $width, int $height): void {
    $user = onboardedUser(['intro_seen_at' => null]);
    BudgetLine::query()->whereRelation('category', 'name', 'Rent')->firstOrFail()->update(['due_day' => 1]);
    $this->actingAs($user);

    $page = visit(route('dashboard'))->on()->mobile()->resize($width, $height);

    $steps = [];
    do {
        $step = $page->script(measureTourStep($theme));
        $steps[] = $step;
        if (! $step['last']) {
            $page->script(pressTour('next'));
        }
    } while (! $step['last'] && count($steps) < 12);

    expect(array_column($steps, 'id'))->toBe(['leftover', 'if-spent', 'daily', 'record', 'no-spend', 'due', 'categories', 'fixed', 'tabs']);
    foreach ($steps as $step) {
        expect($step)->toMatchArray(['spotMatches' => true, 'cardOnScreen' => true, 'cardClearOfSpot' => true, 'focusInCard' => true]);
    }
    // At 393 px every element is lit whole; on a 320×568 phone only the tall cards may be cropped.
    $cropped = array_column(array_filter($steps, fn (array $step): bool => ! $step['whole']), 'id');
    expect($width >= 393 ? $cropped : array_diff($cropped, ['leftover', 'categories', 'fixed']))->toBe([]);

    $page->script(pressTour('back'));
    expect($page->script(measureTourStep($theme))['id'])->toBe('fixed');

    $closed = $page->script(<<<'JS'
        async () => {
            document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
            for (let i = 0; i < 50 && getComputedStyle(document.querySelector('[data-test="tour"]')).display !== 'none'; i++) await new Promise(resolve => setTimeout(resolve, 40))
            return getComputedStyle(document.querySelector('[data-test="tour"]')).display === 'none'
        }
    JS);
    expect($closed)->toBeTrue();

    $seen = null;
    for ($try = 0; $try < 20 && $seen === null; $try++) {
        Sleep::usleep(100_000);
        $seen = $user->settings()->refresh()->intro_seen_at;
    }
    expect($seen)->not->toBeNull();
    $page->assertNoJavaScriptErrors();
})->with([
    'dark 320×568' => ['dark', 320, 568],
    'light 393×852' => ['light', 393, 852],
]);

it('continues the tour on the plan after Today', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('dashboard', ['bemutato' => 1]))->on()->mobile()->resize(393, 852);

    $pressed = 0;
    do {
        $step = $page->script(measureTourStep('dark'));
        $page->script(pressTour('next'));
    } while (! $step['last'] && ++$pressed < 12);

    $plan = $page->script(<<<'JS'
        async () => {
            for (let i = 0; i < 100 && ! (location.pathname === '/terv' && document.querySelector('[data-test="tour-tooltip"]')?.className.includes('opacity-100')); i++) await new Promise(resolve => setTimeout(resolve, 40))
            return { path: location.pathname, counter: document.querySelector('[data-test="tour-counter"]').textContent.replace(/\s+/g, ' ').trim(), bemutato: location.search.includes('bemutato') }
        }
    JS);

    expect($plan)->toBe(['path' => '/terv', 'counter' => 'Plan · 1 / 6', 'bemutato' => false]);
});

it('explains the daily figure', function (): void {
    $this->actingAs(onboardedUser());

    visit(route('dashboard'))->on()->mobile()
        ->click('[data-test="explain-daily"]')
        ->assertVisible('[data-test="explainer-daily"]')
        ->assertSee('Days left, today included')
        ->assertNoJavaScriptErrors();
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

it('opens the due items as list rows with one tonal and one text action, and leaves the fixed item names room on a 320 px phone', function (): void {
    // In Hungarian, where the labels are longest: Kész and Kihagyom still share a row.
    $user = onboardedUser(['locale' => 'hu']);
    BudgetLine::query()->whereRelation('category', 'name', 'Rent')->firstOrFail()->update(['due_day' => 1]);
    $phone = Category::factory()->for($user)->create(['name' => 'Telephone and internet service', 'type' => 'fixed']);
    BudgetLine::factory()->for($user)->for($phone)->create(['amount' => 1_234_567, 'due_day' => 28]);
    $this->actingAs($user);

    $page = visit(route('dashboard'))->on()->mobile()->resize(320, 568);
    $page->click('[data-test="due-summary"]')->assertVisible('[data-test="mark-due-done"]');

    $layout = $page->script(<<<'JS'
        () => {
            const item = document.querySelector('[data-test="due-item"]')
            const done = item.querySelector('[data-test="mark-due-done"]')
            const skip = item.querySelector('[data-test="skip-due"]')
            const name = [...document.querySelectorAll('[data-test="fixed-item"] > span')].find(span => span.textContent.includes('Telephone'))
            return {
                done: done.dataset.variant,
                skip: skip.dataset.variant,
                doneFirst: done.getBoundingClientRect().top <= skip.getBoundingClientRect().top,
                oneRow: Math.abs(done.getBoundingClientRect().top - skip.getBoundingClientRect().top) <= 0.5,
                nameRoom: name.getBoundingClientRect().width >= 150,
                noSideScroll: document.documentElement.scrollWidth <= window.innerWidth,
            }
        }
    JS);

    expect($layout)->toBe(['done' => 'tonal', 'skip' => 'text', 'doneFirst' => true, 'oneRow' => true, 'nameRoom' => true, 'noSideScroll' => true]);
    $page->assertNoJavaScriptErrors();
});

/**
 * Polls for up to two seconds whether the floating + shows: it reacts through observers and slides.
 */
function recordButtonShows(bool $expected): string
{
    return sprintf(<<<'JS'
        async () => {
            const shows = () => document.querySelector('[data-test=tab-entry]').className.includes('translate-y-0')
            for (let i = 0; i < 20 && shows() !== %s; i++) await new Promise(r => setTimeout(r, 100))
            return shows()
        }
    JS, $expected ? 'true' : 'false');
}

/**
 * Scrolls Today's own Record button off the top of the screen, then a little back up, the way that
 * brings the + back: far enough down that the card's button is gone, then up past the threshold.
 */
function scrollPastTodaysRecordButton(): string
{
    return <<<'JS'
        async () => {
            const wait = ms => new Promise(r => setTimeout(r, ms))
            const below = document.querySelector('[data-test=daily-record]').getBoundingClientRect().bottom + window.scrollY
            window.scrollTo(0, below + 80)
            await wait(150)
            window.scrollTo(0, below + 40)
            await wait(150)
            return document.querySelector('[data-test=daily-record]').getBoundingClientRect().bottom < 0
        }
    JS;
}

it('keeps the + away while Today’s own Record button is on screen, so two never sit side by side', function (int $width, int $height): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('dashboard'))->on()->mobile()->resize($width, $height)
        ->assertVisible('[data-test="daily-record"]');

    expect($page->script(recordButtonShows(false)))->toBeFalse()
        ->and($page->script("() => document.querySelector('[data-test=tab-entry]').getAttribute('aria-hidden')"))->toBe('true');

    expect($page->script(scrollPastTodaysRecordButton()))->toBeTrue()
        ->and($page->script(recordButtonShows(true)))->toBeTrue();

    $page->script('() => window.scrollTo(0, 0)');
    expect($page->script(recordButtonShows(false)))->toBeFalse();
    $page->assertNoJavaScriptErrors();
})->with([
    'small phone' => [320, 568],
    'short iPhone' => [393, 659],
]);

it('moves the record button out of the way while the due items are open and brings it back when they fold', function (): void {
    $user = onboardedUser();
    BudgetLine::query()->whereRelation('category', 'name', 'Rent')->firstOrFail()->update(['due_day' => 1]);
    $this->actingAs($user);

    $page = visit(route('dashboard'))->on()->mobile()->resize(393, 659);

    expect($page->script(scrollPastTodaysRecordButton()))->toBeTrue()
        ->and($page->script(recordButtonShows(true)))->toBeTrue();

    $page->click('[data-test="due-summary"]')->assertVisible('[data-test="mark-due-done"]');
    expect($page->script(recordButtonShows(false)))->toBeFalse();

    $page->click('[data-test="due-summary"]')->assertMissing('[data-test="mark-due-done"]:visible');
    expect($page->script(recordButtonShows(true)))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('keeps the actions on Today out from under the record button at rest', function (int $width, int $height, bool $overspent, bool $pendingTransfer): void {
    $user = onboardedUser();
    BudgetLine::query()->whereRelation('category', 'name', 'Rent')->firstOrFail()->update(['due_day' => 1]);
    $period = resolve(PeriodService::class)->current($user);
    if ($overspent) {
        Transaction::factory()->for($user)->for($period)->for(Category::query()->where('name', 'Fuel')->firstOrFail())->create(['amount' => 400_000, 'occurred_on' => $period->starts_on]);
    }
    if ($pendingTransfer) {
        $account = $user->accounts()->create(['name' => 'Brokerage', 'type' => AccountType::Investment, 'currency' => Currency::HUF]);
        PeriodClose::factory()->for($user)->for(resolve(PeriodService::class)->forDate($user, $period->starts_on->subDay()))->create(['surplus_account_id' => $account->id]);
    }
    $this->actingAs($user);

    $page = visit(route('dashboard'))->on()->mobile()->resize($width, $height);

    // What is drawn inside each action (its text and icons), not its box: a full-width row may run under the button with nothing in it.
    $covered = $page->script(<<<'JS'
        async () => {
            await new Promise(resolve => setTimeout(resolve, 400))
            const fab = document.querySelector('[data-test="tab-entry"]').getBoundingClientRect()
            const overlaps = box => box.width > 0 && box.right > fab.left && box.left < fab.right && box.bottom > fab.top && box.top < fab.bottom
            return [...document.querySelectorAll('main button, main a[data-variant]')].filter(action => action.offsetParent !== null).filter(action => {
                const walker = document.createTreeWalker(action, NodeFilter.SHOW_TEXT)
                const boxes = [...action.querySelectorAll('.ms')].map(icon => icon.getBoundingClientRect())
                for (let node = walker.nextNode(); node; node = walker.nextNode()) {
                    if (node.textContent.trim() === '') continue
                    const range = document.createRange()
                    range.selectNodeContents(node)
                    boxes.push(...range.getClientRects())
                }
                return boxes.some(overlaps)
            }).map(action => action.dataset.test ?? action.textContent.trim())
        }
    JS);

    expect($covered)->toBe([]);
})->with([
    'small phone, due items' => [375, 667, false, false],
    'small phone, pending transfer' => [375, 667, false, true],
    'iPhone 15, overspent with a pending transfer' => [393, 852, true, true],
]);
