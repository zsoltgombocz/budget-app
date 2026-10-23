<?php

it('asks in the app’s own dialog and answers with the button pressed', function (): void {
    $this->actingAs(onboardedUser());

    $answers = visit(route('month'))->on()->mobile()->script(<<<'JS'
        async () => {
            const wait = ms => new Promise(resolve => setTimeout(resolve, ms))
            const shown = () => getComputedStyle(document.querySelector('[data-test="confirm-dialog"]')).display !== 'none'
            while (! window.Alpine) await wait(50)
            await wait(300)
            const hiddenAtStart = ! shown()

            const first = window.appConfirm({ title: 'Delete?', body: 'Sure?', confirm: 'Delete', danger: true })
            await wait(300)
            const visible = shown() && document.querySelector('[data-test="confirm-dialog"]').textContent.includes('Delete?')
            document.querySelector('[data-test="confirm-cancel"]').click()

            const second = window.appConfirm({ title: 'Again?' })
            await wait(300)
            document.querySelector('[data-test="confirm-ok"]').click()

            return { hiddenAtStart, visible, cancelled: await first, confirmed: await second }
        }
    JS);

    expect($answers)->toBe(['hiddenAtStart' => true, 'visible' => true, 'cancelled' => false, 'confirmed' => true]);
});

it('stacks the dialog buttons on a 320 px screen, the confirming one on top, so a long label fits', function (int $width, bool $stacked): void {
    $this->actingAs(onboardedUser());

    $layout = visit(route('month'))->on()->mobile()->resize($width, 568)->script(<<<'JS'
        async () => {
            const wait = ms => new Promise(resolve => setTimeout(resolve, ms))
            while (! window.Alpine) await wait(50)
            await wait(300)
            window.appConfirm({ title: 'Undo the closing?', confirm: 'Zárás visszavonása', icon: 'undo' })
            await wait(400)
            const ok = document.querySelector('[data-test="confirm-ok"]')
            const cancel = document.querySelector('[data-test="confirm-cancel"]')
            return { stacked: ok.getBoundingClientRect().bottom <= cancel.getBoundingClientRect().top + 0.5, fits: ok.scrollWidth <= ok.clientWidth }
        }
    JS);

    expect($layout)->toBe(['stacked' => $stacked, 'fits' => true]);
})->with([
    '320 px' => [320, true],
    '393 px' => [393, false],
]);
