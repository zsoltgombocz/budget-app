<?php

use App\Actions\Budget\SaveLoan;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\Loan;
use App\Models\Transaction;
use App\Services\PeriodService;

it('edits a plan amount with the numpad and says the change reaches the open period', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('plan'))->on()->mobile()->resize(390, 844);

    $page->script("() => [...document.querySelectorAll('[data-test=plan-line] button')].find(b => b.textContent.includes('Fuel')).click()");

    $page->waitForText('Edit item')
        ->assertVisible('[data-test="scope-note"]')
        ->assertSee('The change also applies to the current period (from')
        ->click('[data-test="field-amount"]')
        ->click('[data-key="del"]:visible')->click('[data-key="del"]:visible')->click('[data-key="del"]:visible')
        ->click('[data-key="del"]:visible')->click('[data-key="del"]:visible')
        ->click('[data-key="7"]:visible')->click('[data-key="5"]:visible')->click('[data-key="000"]:visible')
        ->click('[data-test="pad-done"]:visible')
        ->click('[data-test="save-line"]')
        ->waitForText('Plan updated.')
        ->assertSee('Also applies to the current period (from')
        ->assertSee('75,000')
        ->assertNoJavaScriptErrors();

    expect(BudgetLine::query()->whereRelation('category', 'name', 'Fuel')->value('amount'))->toBe(75_000);
});

it('fits the Hungarian scope note into the toast on a small iPhone', function (): void {
    $this->actingAs(onboardedUser(['locale' => 'hu']));

    $page = visit(route('plan'))->on()->mobile()->resize(375, 667);

    $page->script("() => [...document.querySelectorAll('[data-test=plan-line] button')].find(b => b.textContent.includes('Fuel')).click()");

    $page->waitForText('Tétel szerkesztése')
        ->assertSee('A változás a mostani periódusra is érvényes (')
        ->click('[data-test="save-line"]')
        ->waitForText('Terv frissítve.')
        ->assertSee('A mostani periódusra is érvényes (');

    $subtitle = $page->script(<<<'JS'
        () => {
            const line = [...document.querySelectorAll('[data-test="toast"] .truncate')].at(-1)
            return { text: line.textContent, cut: line.scrollWidth > line.clientWidth }
        }
    JS);

    expect($subtitle['text'])->toMatch('/^A mostani periódusra is érvényes \(\S+ \d+-t[őó]l\)\.$/u')
        ->and($subtitle['cut'])->toBeFalse();
});

it('asks before a type change that recounts spending, and saves only on confirm', function (): void {
    $user = onboardedUser();
    $fuel = Category::query()->where('name', 'Fuel')->firstOrFail();
    Transaction::factory()->for($user)->for(resolve(PeriodService::class)->current($user))->for($fuel)->create(['amount' => 12_000]);
    $this->actingAs($user);

    $page = visit(route('plan'))->on()->mobile()->resize(390, 844);

    $page->script("() => [...document.querySelectorAll('[data-test=plan-line] button')].find(b => b.textContent.includes('Fuel')).click()");

    $page->waitForText('Edit item')
        ->click('[data-test="type-fixed"]')
        ->click('[data-test="save-line"]')
        ->waitForText('Change the kind of this item?')
        ->assertSee('12,000')
        ->click('[data-test="confirm-cancel"]')
        ->wait(0.4)
        ->assertVisible('[data-test="save-line"]');

    expect($fuel->refresh()->type->value)->toBe('variable');

    $page->click('[data-test="save-line"]')
        ->waitForText('Change the kind of this item?')
        ->click('[data-test="confirm-ok"]')
        ->waitForText('Plan updated.')
        ->assertNoJavaScriptErrors();

    expect($fuel->refresh()->type->value)->toBe('fixed');
});

it('deletes a line with the text button at the bottom of the sheet', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('plan'))->on()->mobile()->resize(390, 844);

    $page->script("() => [...document.querySelectorAll('[data-test=plan-line] button')].find(b => b.textContent.includes('Rent')).click()");

    $page->waitForText('Edit item');
    $page->script("() => document.querySelector('[data-test=\"delete-line\"]').scrollIntoView()");
    $page->click('[data-test="delete-line"]')
        ->waitForText('Remove this line from the plan?')
        ->assertSee('The item also leaves the plan of the current period')
        ->click('[data-test="confirm-ok"]')
        ->waitForText('Item removed from the plan.')
        ->assertNoJavaScriptErrors();

    expect(BudgetLine::query()->whereRelation('category', 'name', 'Rent')->exists())->toBeFalse();
});

it('shows edit mode as a state and leaves it with Done', function (): void {
    $this->actingAs(onboardedUser());

    visit(route('plan'))->on()->mobile()->resize(390, 844)
        ->assertMissing('[data-test="line-handle"]')
        ->click('[data-test="toggle-edit"]')
        ->waitForText('Drag an item by its handle to reorder, tap it to edit.')
        ->assertVisible('[data-test="group-fixed"] [data-test="line-handle"]')
        ->assertVisible('[data-test="add-fixed"]')
        ->click('[data-test="toggle-edit"]')
        ->waitForText('Monthly plan')
        ->assertMissing('[data-test="line-handle"]')
        ->assertNoJavaScriptErrors();
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

it('sets the leftover rules inline on the plan and creates a reserve there with the numpad', function (): void {
    $this->actingAs(onboardedUser());

    visit(route('plan'))->on()->mobile()
        ->assertSee('All of it stays on the account')
        ->assertVisible('[data-test="leftover-preview"]')
        ->click('[data-test="create-reserve"]')
        ->assertVisible('[data-test="reserve-sheet"]')
        ->click('[data-test="reserve-monthly"]')
        ->click('[data-test="reserve-sheet"] [data-key="2"]:visible')
        ->click('[data-test="reserve-sheet"] [data-key="5"]:visible')
        ->click('[data-test="reserve-sheet"] [data-key="000"]:visible')
        ->click('[data-test="pad-done"]:visible')
        ->click('[data-test="save-reserve"]')
        ->assertVisible('[data-test="reserve-row"] >> nth=0')
        ->click('[data-test="reserve-pct-50"]')
        ->waitForText('50% to the reserve, the rest stays on the account')
        ->assertDontSee('Plan updated.')
        ->assertNoJavaScriptErrors();

    expect(BudgetLine::query()->whereNotNull('pocket_id')->value('amount'))->toBe(25_000)
        ->and(auth()->user()->settings()->refresh()->reserve_pct)->toBe(50);
});
