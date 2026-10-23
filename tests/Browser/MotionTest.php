<?php

/** Root attribute, stored choice and what a sheet's panel computes for its transition. */
const MOTION_STATE = <<<'JS'
    () => ({
        motion: document.documentElement.dataset.motion,
        stored: localStorage.getItem('motion'),
        checked: document.querySelector('[data-test="motion-toggle"]')?.getAttribute('aria-checked') ?? null,
    })
JS;

it('turns the animations off from Appearance, keeps it across navigation and makes sheets instant', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('appearance.edit'))->on()->mobile()->resize(393, 852);

    expect($page->script(MOTION_STATE))->toBe(['motion' => 'on', 'stored' => null, 'checked' => 'true']);

    $page->click('[data-test="motion-toggle"]');

    expect($page->script(MOTION_STATE))->toBe(['motion' => 'off', 'stored' => 'off', 'checked' => 'false']);

    // wire:navigate copies the next page's <html> attributes; the choice must survive it.
    $state = $page->script(<<<'JS'
        async () => {
            const wait = ms => new Promise(resolve => setTimeout(resolve, ms))
            document.querySelector('nav a[href$="/ma"]').click()
            for (let i = 0; i < 50 && ! document.querySelector('[data-test="explain-month-left"]'); i++) await wait(100)
            const motionAfterNavigate = document.documentElement.dataset.motion
            document.querySelector('[data-test="explain-month-left"]').click()
            await wait(50)
            const panel = document.querySelector('[data-test="explainer-month-left"]')
            const style = getComputedStyle(panel)
            return {
                motionAfterNavigate,
                transitionDuration: style.transitionDuration,
                animationDuration: getComputedStyle(document.querySelector('.page-enter')).animationDuration,
                // Instant: the sheet is already in place, no slide in progress.
                transform: style.transform,
            }
        }
    JS);

    expect($state['motionAfterNavigate'])->toBe('off')
        ->and($state['transitionDuration'])->toMatch('/^0s(, 0s)*$/')
        ->and($state['animationDuration'])->toBe('0s')
        ->and($state['transform'])->toBeIn(['none', 'matrix(1, 0, 0, 1, 0, 0)']);

    // After a full reload the head script applies it before the first paint.
    $page->navigate(route('appearance.edit'));

    expect($page->script(MOTION_STATE))->toBe(['motion' => 'off', 'stored' => 'off', 'checked' => 'false']);
});

it('animates sheets while the animations are on', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('month'))->on()->mobile()->resize(393, 852);

    $duration = $page->script(<<<'JS'
        async () => {
            for (let i = 0; i < 50 && ! window.Alpine; i++) await new Promise(resolve => setTimeout(resolve, 100))
            document.querySelector('[data-test="add-fixed-line"]').click()
            await new Promise(resolve => requestAnimationFrame(resolve))
            return getComputedStyle(document.querySelector('[data-test="line-sheet"]')).transitionDuration
        }
    JS);

    expect($duration)->not->toMatch('/^0s(, 0s)*$/');
});
