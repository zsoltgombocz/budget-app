<?php

use App\Models\BudgetLine;

it('edits a plan amount with the numpad and saves it', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('plan'))->on()->mobile()->resize(390, 844);

    $page->script("() => [...document.querySelectorAll('[data-test=plan-line] button')].find(b => b.textContent.includes('Fuel')).click()");

    $page->waitForText('Edit item')
        ->click('[data-test="field-amount"]')
        ->click('[data-key="del"]:visible')->click('[data-key="del"]:visible')->click('[data-key="del"]:visible')
        ->click('[data-key="del"]:visible')->click('[data-key="del"]:visible')
        ->click('[data-key="7"]:visible')->click('[data-key="5"]:visible')->click('[data-key="000"]:visible')
        ->click('[data-test="save-line"]')
        ->waitForText('Plan updated.')
        ->assertSee('75,000')
        ->assertNoJavaScriptErrors();

    expect(BudgetLine::query()->whereRelation('category', 'name', 'Fuel')->value('amount'))->toBe(75_000);
});
