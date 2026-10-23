<?php

/**
 * The tap height of an element: how far up and down from its centre a tap still lands on it
 * (an invisible ::before counts, as it does for a finger).
 */
function tapHeight(mixed $page, string $selector): int
{
    return (int) $page->script(sprintf(<<<'JS'
        () => {
            const element = document.querySelector(%s)
            const box = element.getBoundingClientRect()
            const x = box.left + box.width / 2
            const hits = y => { const hit = document.elementFromPoint(x, y); return !! hit && (hit === element || element.contains(hit)) }
            let top = box.top + box.height / 2
            let bottom = top
            while (hits(top - 1)) top--
            while (hits(bottom + 1)) bottom++
            return Math.round(bottom - top + 1)
        }
    JS, json_encode($selector)));
}

it('keeps the entry sheet controls tappable and the Save row on screen at 320x568', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('entry'))->on()->mobile()->resize(320, 568)->wait(0.6);

    foreach (['[data-test="entry-cancel"]', '[data-test="entry-date"]', '[data-test="entry-note"]'] as $selector) {
        expect(tapHeight($page, $selector))->toBeGreaterThanOrEqual(44, $selector);
    }

    $fits = $page->script(<<<'JS'
        () => {
            const save = document.querySelector('[data-test="save-entry"]').getBoundingClientRect()
            return save.bottom <= window.innerHeight && document.documentElement.scrollWidth <= window.innerWidth
        }
    JS);

    expect($fits)->toBeTrue();
});

it('gives small buttons, segments and settings controls a 44px touch target', function (): void {
    $this->actingAs(onboardedUser());

    $budget = visit(route('budget.edit'))->on()->mobile()->resize(375, 667)->wait(0.5);
    expect(tapHeight($budget, '[data-test="period-payday"]'))->toBeGreaterThanOrEqual(44);

    $profile = visit(route('profile.edit'))->on()->mobile()->resize(375, 667)->wait(0.5);
    // The whole field box focuses the input (it sits in the label), so the box is the target.
    expect(tapHeight($profile, 'form label:has(input[type="text"]) > span.h-12'))->toBeGreaterThanOrEqual(44);
});
