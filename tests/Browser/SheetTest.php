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
            const sheet = document.querySelector('[data-test="save-income"]').closest('.rounded-t-\\[30px\\]').getBoundingClientRect()
            return { top: Math.round(sheet.top), bottom: Math.round(sheet.bottom), viewport: window.innerHeight }
        }
    JS);

    expect($box['bottom'])->toBe($box['viewport'])
        ->and($box['top'])->toBeGreaterThanOrEqual(0);
});
