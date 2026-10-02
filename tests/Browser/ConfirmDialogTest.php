<?php

it('asks in the app’s own dialog and answers with the button pressed', function (): void {
    $this->actingAs(onboardedUser());

    $answers = visit(route('plan'))->on()->mobile()->script(<<<'JS'
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
