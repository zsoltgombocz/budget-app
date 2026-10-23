<?php

use App\Enums\PeriodStatus;
use App\Models\PeriodClose;
use App\Services\PeriodService;

it('marks the tab and shows the loader as soon as it is tapped', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('dashboard'))->on()->mobile();

    $state = $page->script(<<<'JS'
        async () => {
            const link = document.querySelector('nav a[href$="/honap"]')
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

it('sits in the tab bar\'s row, right of the pill', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('month'))->on()->mobile();

    $boxes = $page->script("() => { const fab = document.querySelector('[data-test=\"tab-entry\"]').getBoundingClientRect(); const pill = document.querySelector('nav[aria-label] > div > div').getBoundingClientRect(); return { right: fab.left >= pill.right, row: Math.abs((fab.top + fab.bottom) / 2 - (pill.top + pill.bottom) / 2) < 2 } }");

    expect($boxes)->toBe(['right' => true, 'row' => true]);
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

    $page->click('[data-test="next-period"]')->assertSee('Left this month');
    expect($page->script($visible))->toBeTrue();

    $page->click('[data-test="previous-period"]')->assertSee('Left over');
    expect($page->script($visible))->toBeFalse();
});
