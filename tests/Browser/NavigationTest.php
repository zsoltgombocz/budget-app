<?php

use App\Enums\PeriodStatus;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\Loan;
use App\Models\PeriodClose;
use App\Models\Pocket;
use App\Services\PeriodService;

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

it('has no record button on the settings sub-pages either', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('budget.edit'))->on()->mobile();

    $visible = $page->script("async () => { await new Promise(r => setTimeout(r, 600)); return document.querySelector('[data-test=\"tab-entry\"]').className.includes('translate-y-0') }");

    expect($visible)->toBeFalse();
});

it('hides the record button on a closed month and shows it again on the open one', function (): void {
    $user = onboardedUser();
    $this->actingAs($user);
    $current = resolve(PeriodService::class)->current($user);
    $previous = resolve(PeriodService::class)->forDate($user, $current->starts_on->subDay());
    $previous->update(['status' => PeriodStatus::Closed]);
    PeriodClose::factory()->for($user)->for($previous)->create();

    $visible = "async () => { await new Promise(r => setTimeout(r, 500)); return document.querySelector('[data-test=tab-entry]').className.includes('translate-y-0') }";
    $page = visit(route('month', ['periodus' => $previous->id]))->on()->mobile()->assertSee('September');

    expect($page->script($visible))->toBeFalse();

    $page->click('[data-test="next-period"]')->assertSee('Close the month');
    expect($page->script($visible))->toBeTrue();

    $page->click('[data-test="previous-period"]')->assertSee('To the reserve');
    expect($page->script($visible))->toBeFalse();
});

it('keeps every action control out from under the record button at rest', function (string $route, int $width, int $height): void {
    $user = onboardedUser();
    BudgetLine::query()->whereRelation('category', 'name', 'Rent')->firstOrFail()->update(['due_day' => 1]);
    foreach (['Utilities' => 35_000, 'Phone and internet' => 12_000, 'Insurance' => 9_000] as $name => $amount) {
        BudgetLine::factory()->for($user)->for(Category::factory()->for($user)->create(['name' => $name, 'type' => 'fixed']))->create(['amount' => $amount, 'due_day' => 12]);
    }
    $loan = Loan::factory()->for($user)->create(['name' => 'Personal loan', 'lender' => 'Demo Bank']);
    Pocket::factory()->for($user)->create(['name' => 'Prepayment pocket', 'balance' => 450_000, 'prepay_step' => 500_000, 'loan_id' => $loan->id]);
    Pocket::factory()->for($user)->create(['name' => 'Reserve', 'is_reserve' => true, 'balance' => 204_522, 'target_amount' => 300_000]);
    $this->actingAs($user);

    $page = visit(route($route))->on()->mobile()->resize($width, $height);

    // The buttons, switches and chips (not whole list rows, whose amount may run under the +), with half a pixel of slack.
    $covered = $page->script(<<<'JS'
        async () => {
            await new Promise(resolve => setTimeout(resolve, 500))
            const fabButton = document.querySelector('[data-test="tab-entry"]')
            if (! fabButton.className.includes('translate-y-0')) return []
            const fab = fabButton.getBoundingClientRect()
            const overlaps = box => box.width > 0 && box.right > fab.left + 0.5 && box.left < fab.right - 0.5 && box.bottom > fab.top + 0.5 && box.top < fab.bottom - 0.5
            return [...document.querySelectorAll('main [data-variant], main [role=switch], main [aria-pressed]')]
                .filter(control => control.offsetParent !== null && overlaps(control.getBoundingClientRect()))
                .map(control => control.dataset.test ?? control.textContent.trim())
        }
    JS);

    expect($covered)->toBe([]);
})->with([
    'Today' => ['dashboard', 393, 659],
    'Plan' => ['plan', 393, 659],
    'Pockets' => ['pockets', 393, 659],
    'Pockets, small phone' => ['pockets', 320, 568],
]);
