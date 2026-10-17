<?php

use App\Models\BudgetLine;

it('replaces a filled amount on the first key, takes a physical keyboard and closes only the pad on Escape', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('plan'))->on()->mobile()->resize(390, 844);
    $page->script("() => [...document.querySelectorAll('[data-test=plan-line] button')].find(b => b.textContent.includes('Groceries')).click()");

    $page->waitForText('Edit item')
        ->click('[data-test="line-sheet"] [data-test="field-amount"]')
        ->assertVisible('[data-test="line-sheet"] [data-test="amount-pad"] [role="status"]');

    // Focus moves into the pad after its open transition; wait for it rather than racing it.
    expect($page->script("async () => { for (let i = 0; i < 40; i++) { if (document.activeElement?.closest('[data-test=amount-pad]')) return true; await new Promise(r => setTimeout(r, 50)) } return false }"))->toBeTrue();

    $page->keys('[data-test="line-sheet"] [data-test="amount-pad"] [role="group"]', ['9', '5', '0', '0', '1', 'Backspace', '0'])
        ->assertSeeIn('[data-test="line-sheet"] [data-test="amount-pad"] [role="status"]', '95,000')
        ->keys('[data-test="line-sheet"] [data-test="amount-pad"] [role="group"]', ['Escape'])
        ->wait(0.4)
        ->assertMissing('[data-test="line-sheet"] [data-test="amount-pad"] [role="status"]')
        ->assertVisible('[data-test="save-line"]')
        ->assertSeeIn('[data-test="line-sheet"] [data-test="field-amount"]', '95,000');

    expect($page->script("async () => { for (let i = 0; i < 40; i++) { if (document.activeElement?.getAttribute('data-test') === 'field-amount') break; await new Promise(r => setTimeout(r, 50)) } return document.activeElement?.getAttribute('data-test') }"))->toBe('field-amount');

    $page->click('[data-test="save-line"]')->waitForText('Plan updated.')->assertNoJavaScriptErrors();

    expect(BudgetLine::query()->whereRelation('category', 'name', 'Groceries')->value('amount'))->toBe(95_000);
});

it('confirms a numpad sheet with Enter and names the keys', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('plan'))->on()->mobile()->resize(390, 844)
        ->click('[data-test="income"]')
        ->assertVisible('[data-test="income-sheet"]')
        ->assertAttribute('[data-test="income-sheet"] [data-key="del"]', 'aria-label', 'Delete the last digit');

    $page->keys('[data-test="income-sheet"] [data-key="1"]', ['6', '0', '0', '0', '0', '0', 'Enter'])
        ->waitForText('Plan updated.')
        ->assertNoJavaScriptErrors();

    expect(auth()->user()->settings()->refresh()->income)->toBe(600_000);
});
