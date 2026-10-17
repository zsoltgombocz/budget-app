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
            const line = [...document.querySelectorAll('[data-test="toast-subtitle"]')].at(-1)
            return { text: line.textContent, cut: line.scrollWidth > line.clientWidth || line.scrollHeight > line.clientHeight }
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
        ->click('[data-test="line-more"]')
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
        ->waitForText('Drag an item by its handle or use the arrows to reorder, tap it to edit.')
        ->assertVisible('[data-test="group-fixed"] [data-test="line-handle"]')
        ->assertVisible('[data-test="add-fixed"]')
        ->click('[data-test="toggle-edit"]')
        ->waitForText('Every month starts with this plan.')
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

it('keeps the plan summary inside a 320 px screen with long euro amounts', function (): void {
    $user = onboardedUser(['locale' => 'hu', 'currency' => 'EUR', 'income' => 1_234_567_89]);
    BudgetLine::query()->whereRelation('category', 'name', 'Rent')->update(['amount' => 987_654_32]);
    $this->actingAs($user);

    $page = visit(route('plan'))->on()->mobile()->resize(320, 640);

    $fits = $page->script(<<<'JS'
        () => {
            const card = document.querySelector('[data-test="plan-summary"]').getBoundingClientRect();
            const inside = [...document.querySelectorAll('[data-test="plan-summary"] .num')].every(el => {
                const box = el.getBoundingClientRect();
                // Half a pixel of slack: a right-aligned amount ends exactly on the edge, and Linux
                // fonts land a sub-pixel past it.
                return box.left >= card.left - 0.5 && box.right <= card.right + 0.5;
            });
            return inside && document.documentElement.scrollWidth <= window.innerWidth;
        }
    JS);

    expect($fits)->toBeTrue();
});

it('keeps the list from showing through the stuck plan summary and draws a hairline under it', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('plan'))->on()->mobile()->resize(320, 568);

    $band = $page->script(<<<'JS'
        async () => {
            const band = document.querySelector('[data-test="plan-summary-band"]')
            const alpha = color => {
                const parts = color.match(/[\d.]+%?/g) ?? []
                const slash = color.includes('/') ? color.split('/').pop() : null
                const value = slash ?? (color.startsWith('rgba') ? parts[3] : '1')
                return value.trim().endsWith('%') ? parseFloat(value) / 100 : parseFloat(value)
            }
            const atRest = alpha(getComputedStyle(band).borderBottomColor)
            window.scrollTo(0, 400)
            // Polls: the page fades up on entry and the hairline fades in.
            const settled = () => Math.abs(band.getBoundingClientRect().top - parseFloat(getComputedStyle(band).top)) <= 0.5 && alpha(getComputedStyle(band).borderBottomColor) > 0.05
            for (let i = 0; i < 30 && ! settled(); i++) await new Promise(r => setTimeout(r, 100))
            const style = getComputedStyle(band)
            return {
                stuck: Math.abs(band.getBoundingClientRect().top - parseFloat(style.top)) <= 0.5,
                opaque: alpha(style.backgroundColor) >= 0.95,
                hairlineAtRest: atRest > 0,
                hairlineStuck: alpha(style.borderBottomColor) > 0,
            }
        }
    JS);

    expect($band)->toBe(['stuck' => true, 'opaque' => true, 'hairlineAtRest' => false, 'hairlineStuck' => true]);
});

it('changes a budget in four taps: line, amount, numpad, save', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('plan'))->on()->mobile()->resize(390, 844);
    $page->script("() => [...document.querySelectorAll('[data-test=plan-line] button')].find(b => b.textContent.includes('Groceries')).click()");

    $page->waitForText('Edit item')
        ->assertMissing('[data-test="type-fixed"]')
        ->click('[data-test="line-sheet"] [data-test="field-amount"]')
        ->click('[data-test="line-sheet"] [data-test="amount-pad"] [data-key="9"]')
        ->click('[data-test="line-sheet"] [data-test="amount-pad"] [data-key="5"]')
        ->click('[data-test="line-sheet"] [data-test="amount-pad"] [data-key="000"]')
        ->click('[data-test="pad-done"]:visible')
        ->click('[data-test="save-line"]')
        ->waitForText('Plan updated.')
        ->assertNoJavaScriptErrors();

    expect(BudgetLine::query()->whereRelation('category', 'name', 'Groceries')->value('amount'))->toBe(95_000);
});

it('picks a due day from a day grid', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('plan'))->on()->mobile()->resize(390, 844);
    $page->script("() => [...document.querySelectorAll('[data-test=plan-line] button')].find(b => b.textContent.includes('Rent')).click()");

    $page->waitForText('Edit item')
        ->click('[data-test="field-due-day"] [data-test="day-field"]')
        ->click('[data-test="field-due-day"] [data-day="12"]')
        ->assertSeeIn('[data-test="field-due-day"] [data-test="day-value"]', '12.')
        ->click('[data-test="save-line"]')
        ->waitForText('Plan updated.')
        ->assertNoJavaScriptErrors();

    expect(BudgetLine::query()->whereRelation('category', 'name', 'Rent')->value('due_day'))->toBe(12);
});

it('reorders with the arrow buttons and keeps the focus on the moved line', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('plan'))->on()->mobile()->resize(390, 844)
        ->click('[data-test="toggle-edit"]')
        ->click('[aria-label="Move down: Fuel"]')
        ->waitForText('Order saved.');

    expect($page->script("() => document.activeElement?.getAttribute('aria-label')"))->toBe('Move up: Fuel')
        ->and(Category::query()->where('type', 'variable')->orderBy('sort')->pluck('name')->all())->toBe(['Groceries', 'Fuel']);

    $page->assertSeeIn('[data-test="reorder-status"]', 'Fuel is now in place 2 of 2.')->assertNoJavaScriptErrors();
});

it('moves the focus to the name a new item is missing and ties the message to it', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('plan'))->on()->mobile()->resize(390, 844)
        ->click('[data-test="toggle-edit"]')
        ->click('[data-test="add-variable"]')
        ->waitForText('New item')
        ->click('[data-test="save-line"]')
        ->wait(0.5);

    expect($page->script("() => document.activeElement?.getAttribute('data-test')"))->toBe('line-name')
        ->and($page->script("() => document.activeElement?.getAttribute('aria-invalid')"))->toBe('true')
        ->and($page->script("() => document.getElementById(document.activeElement?.getAttribute('aria-describedby'))?.textContent.trim()"))->not->toBe('');
});

it('keeps whole names in edit mode on a 320 px screen, moving the amount under a long one', function (): void {
    $user = onboardedUser();
    Category::query()->where('name', 'Rent')->update(['name' => 'Phone and internet']);
    BudgetLine::query()->whereRelation('category', 'name', 'Phone and internet')->update(['due_day' => 12]);
    $this->actingAs($user);

    $page = visit(route('plan'))->on()->mobile()->resize(320, 568)
        ->click('[data-test="toggle-edit"]')
        ->waitForText('Drag an item by its handle or use the arrows to reorder, tap it to edit.');

    $names = $page->script(<<<'JS'
        () => [...document.querySelectorAll('[data-test="plan-line"] [data-test="line-name"]')].map(name => ({
            text: name.textContent.trim(),
            cut: name.scrollWidth > name.clientWidth,
        }))
    JS);

    expect($names)->toHaveCount(3)
        ->and(collect($names)->where('cut', true)->all())->toBe([])
        ->and($page->script('() => document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
});
