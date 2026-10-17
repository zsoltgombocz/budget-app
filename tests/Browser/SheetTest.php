<?php

it('keeps sheets pinned to the screen after the page has been scrolled', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('plan'))->on()->mobile()->resize(390, 640);

    $box = $page->script(<<<'JS'
        async () => {
            await new Promise(resolve => setTimeout(resolve, 600))
            window.scrollTo(0, document.body.scrollHeight)
            await new Promise(resolve => setTimeout(resolve, 100))
            document.querySelector('[data-test="income"]').click()
            await new Promise(resolve => setTimeout(resolve, 500))
            const sheet = document.querySelector('[data-test="income-sheet"] [data-test="numpad-done"]').closest('.rounded-t-\\[30px\\]').getBoundingClientRect()
            return { top: Math.round(sheet.top), bottom: Math.round(sheet.bottom), viewport: window.innerHeight }
        }
    JS);

    expect($box['bottom'])->toBe($box['viewport'])
        ->and($box['top'])->toBeGreaterThanOrEqual(0);
});

it('closes only the topmost dialog on Escape and gives focus back to the opener', function (): void {
    $this->actingAs(onboardedUser());

    $state = <<<'JS'
        () => ({
            confirm: getComputedStyle(document.querySelector('[data-test="confirm-dialog"]')).display !== 'none',
            sheet: getComputedStyle(document.querySelector('[data-test="income-sheet"]').closest('[role="dialog"]')).display !== 'none',
            focusInSheet: !! document.activeElement.closest('[role="dialog"]'),
            focusOnOpener: document.activeElement === document.querySelector('[data-test="income"]'),
            locked: document.body.style.position === 'fixed',
        })
    JS;

    $page = visit(route('plan'))->on()->mobile()->resize(390, 640)->wait(0.5)
        ->click('[data-test="income"]')->wait(0.6);

    expect($page->script($state))->toMatchArray(['sheet' => true, 'focusInSheet' => true, 'locked' => true]);

    $page->script("() => { window.appConfirm({ title: 'Test' }) }");
    $page->wait(0.4)->keys('[data-test="confirm-cancel"]', 'Escape')->wait(0.4);

    expect($page->script($state))->toMatchArray(['confirm' => false, 'sheet' => true, 'locked' => true]);

    $page->keys('[data-test="income-sheet"]', 'Escape')->wait(0.5);

    expect($page->script($state))->toMatchArray(['sheet' => false, 'locked' => false, 'focusOnOpener' => true]);
});

it('insets the sheet header symmetrically, in line with the intro, and keeps the title centred', function (int $width, float $inset): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('plan'))->on()->mobile()->resize($width, 700)->wait(0.5)
        ->click('[data-test="income"]')->wait(0.6);

    $box = $page->script(<<<'JS'
        () => {
            const header = document.querySelector('[data-test="income-sheet"] [data-test="sheet-header"]')
            const panel = header.parentElement.getBoundingClientRect()
            const range = document.createRange()
            range.selectNodeContents(header.querySelector('button'))
            const cancel = range.getBoundingClientRect()
            const title = header.querySelector('[data-test="sheet-title"]').getBoundingClientRect()
            return { cancelLeft: cancel.left - panel.left, titleCentre: title.left + title.width / 2 - panel.left, panelCentre: panel.width / 2 }
        }
    JS);

    expect($box['cancelLeft'])->toBeGreaterThanOrEqual($inset - 0.5)->toBeLessThanOrEqual($inset + 0.5)
        ->and(abs($box['titleCentre'] - $box['panelCentre']))->toBeLessThanOrEqual(0.5);
})->with([[393, 24.0], [320, 20.0]]);
