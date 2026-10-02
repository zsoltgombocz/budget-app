<?php

use App\Actions\Budget\SaveLoan;
use App\Models\BudgetLine;
use App\Models\Loan;

it('edits a plan amount with the numpad and saves it', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('plan'))->on()->mobile()->resize(390, 844);

    $page->script("() => [...document.querySelectorAll('[data-test=plan-line] button')].find(b => b.textContent.includes('Fuel')).click()");

    $page->waitForText('Edit item')
        ->click('[data-test="field-amount"]')
        ->click('[data-key="del"]:visible')->click('[data-key="del"]:visible')->click('[data-key="del"]:visible')
        ->click('[data-key="del"]:visible')->click('[data-key="del"]:visible')
        ->click('[data-key="7"]:visible')->click('[data-key="5"]:visible')->click('[data-key="000"]:visible')
        ->click('[data-test="pad-done"]:visible')
        ->click('[data-test="save-line"]')
        ->waitForText('Plan updated.')
        ->assertSee('75,000')
        ->assertNoJavaScriptErrors();

    expect(BudgetLine::query()->whereRelation('category', 'name', 'Fuel')->value('amount'))->toBe(75_000);
});

it('goes back to the plan when the loan opened from there is cancelled', function (): void {
    $user = onboardedUser();
    $loan = Loan::factory()->for($user)->create(['name' => 'Car loan', 'installment' => 40_000, 'insurance' => 0]);
    SaveLoan::addPlanLine($user, $loan);
    $this->actingAs($user);

    $page = visit(route('plan'))->on()->mobile();
    $page->click('[data-test="open-loan-'.$loan->id.'"]')
        ->assertPathIs('/perselyek')
        ->assertVisible('[data-test="loan-sheet"]')
        ->click('[data-test="loan-sheet"] >> text='.__('Cancel'))
        ->assertPathIs('/terv');
});
