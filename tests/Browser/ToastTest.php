<?php

/**
 * Shows a toast that can be undone and measures it: the layout checks run in the page, polling
 * instead of relying on animation timing.
 */
function measureUndoToast(string $theme): string
{
    return <<<JS
        async () => {
            const wait = ms => new Promise(resolve => setTimeout(resolve, ms))
            const until = async (check, tries = 40) => { for (let i = 0; i < tries && ! check(); i++) await wait(50); return check() }
            document.documentElement.classList.toggle('dark', '{$theme}' === 'dark')
            const fab = document.querySelector('[data-test="tab-entry"]')
            const fabShown = () => fab.className.includes('translate-y-0')
            await until(fabShown)
            window.dispatchEvent(new CustomEvent('app-toast', { detail: { title: 'Spending deleted.', icon: 'delete', undo: 'toast-test-undo' } }))
            await until(() => document.querySelector('[data-test="toast"]') !== null)
            const toast = document.querySelector('[data-test="toast"]')
            const title = toast.querySelector('[data-test="toast-title"]').getBoundingClientRect()
            const undo = toast.querySelector('[data-test="toast-undo"]').getBoundingClientRect()
            const box = toast.getBoundingClientRect()
            const nav = document.querySelector('nav[aria-label]').getBoundingClientRect()
            const fabHidden = await until(() => ! fabShown())
            const [r, g, b] = getComputedStyle(toast).backgroundColor.match(/\\d+/g).map(Number)
            const icon = toast.querySelector('[data-test="toast-icon"]')
            const result = {
                oneRow: undo.top < title.bottom && undo.bottom > title.top && undo.left >= title.right,
                aboveTabBar: box.bottom <= nav.top && nav.top - box.bottom <= 16,
                insideScreen: box.left >= 0 && box.right <= window.innerWidth && document.documentElement.scrollWidth <= window.innerWidth,
                noCloseButton: toast.querySelector('[data-test="toast-close"]') === null,
                darkBubble: r + g + b < 200,
                mutedDeleteIcon: icon.textContent === 'delete' && icon.className.includes('toast-muted'),
                iconCentred: Math.abs((icon.getBoundingClientRect().top + icon.getBoundingClientRect().bottom) / 2 - (title.top + title.bottom) / 2) <= 1,
                fabHidden,
            }
            toast.click()
            result.dismissedOnTap = await until(() => document.querySelector('[data-test="toast"]') === null)
            result.fabBack = await until(fabShown)

            return result
        }
    JS;
}

it('shows a toast with undo on one row right above the tab bar, with the record button out of the way', function (string $theme, int $width, int $height): void {
    $this->actingAs(onboardedUser());

    // On the plan: on Today the + already stays away while the card's own Record button is on screen.
    $page = visit(route('plan'))->on()->mobile()->resize($width, $height);

    expect($page->script(measureUndoToast($theme)))->toBe([
        'oneRow' => true,
        'aboveTabBar' => true,
        'insideScreen' => true,
        'noCloseButton' => true,
        'darkBubble' => true,
        'mutedDeleteIcon' => true,
        'iconCentred' => true,
        'fabHidden' => true,
        'dismissedOnTap' => true,
        'fabBack' => true,
    ]);
    $page->assertNoJavaScriptErrors();
})->with([
    'dark 320' => ['dark', 320, 568],
    'light 393' => ['light', 393, 852],
]);
