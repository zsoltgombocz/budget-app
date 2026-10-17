<?php

use App\Models\Loan;
use App\Models\Pocket;
use App\Services\PeriodService;

it('deposits into a pocket straight from its card', function (): void {
    $user = onboardedUser();
    $pocket = Pocket::factory()->for($user)->create(['name' => 'Holiday', 'balance' => 20_000, 'target_amount' => 300_000]);
    $this->actingAs($user);

    $page = visit(route('pockets'))->on()->mobile()->resize(390, 844);

    $page->click('[data-test="pocket-deposit-'.$pocket->id.'"]')
        ->assertVisible('[data-test="pocket-sheet"]')
        ->assertAttribute('[data-test="mode-deposit"]', 'aria-checked', 'true')
        ->assertDisabled('[data-test="pocket-move"]')
        ->click('[data-test="money-pad"] [data-key="1"]')
        ->click('[data-test="money-pad"] [data-key="5"]')
        ->click('[data-test="money-pad"] [data-key="000"]')
        ->assertSeeIn('[data-test="move-amount"]', '15,000')
        ->assertEnabled('[data-test="pocket-move"]')
        ->click('[data-test="pocket-move"]')
        ->waitForText('Deposited: '.money(15_000))
        ->assertSeeIn('[data-test="pocket-card-'.$pocket->id.'"]', '35,000')
        ->assertNoJavaScriptErrors();

    expect($pocket->refresh()->balance)->toBe(35_000)
        ->and(resolve(PeriodService::class)->topUps(resolve(PeriodService::class)->current($user)))->toBe(-15_000);
});

it('withdraws to the monthly budget from the card', function (): void {
    $user = onboardedUser();
    $pocket = Pocket::factory()->for($user)->create(['name' => 'Holiday', 'balance' => 20_000]);
    $this->actingAs($user);

    visit(route('pockets'))->on()->mobile()->resize(390, 844)
        ->click('[data-test="pocket-withdraw-'.$pocket->id.'"]')
        ->assertAttribute('[data-test="mode-budget"]', 'aria-checked', 'true')
        ->click('[data-test="money-pad"] [data-key="5"]')
        ->click('[data-test="money-pad"] [data-key="000"]')
        ->click('[data-test="pocket-move"]')
        ->waitForText('Withdrawn: '.money(5_000))
        ->assertNoJavaScriptErrors();

    expect($pocket->refresh()->balance)->toBe(15_000)
        ->and(resolve(PeriodService::class)->topUps(resolve(PeriodService::class)->current($user)))->toBe(5_000);
});

it('edits the target on the settings tab and asks before deleting from its bottom', function (): void {
    $user = onboardedUser();
    $pocket = Pocket::factory()->for($user)->create(['name' => 'Holiday', 'balance' => 20_000, 'target_amount' => null]);
    $this->actingAs($user);

    visit(route('pockets'))->on()->mobile()->resize(390, 844)
        ->click('[data-test="pocket-'.$pocket->id.'"]')
        ->click('[data-test="pocket-sheet"] [data-test="tabs-settings"]')
        ->assertMissing('[data-test="money-pad"]')
        ->click('[data-test="field-target"]')
        ->click('[data-test="pocket-sheet"] [data-test="amount-pad"] [data-key="9"]')
        ->click('[data-test="pocket-sheet"] [data-test="amount-pad"] [data-key="000"]')
        ->click('[data-test="pad-done"]:visible')
        ->click('[data-test="pocket-sheet"] [data-test="sheet-save"]')
        ->waitForText('Pocket saved.')
        ->click('[data-test="pocket-'.$pocket->id.'"]')
        ->click('[data-test="pocket-sheet"] [data-test="tabs-settings"]')
        ->click('[data-test="delete-pocket"]')
        ->waitForText('Delete this pocket?')
        ->assertNoJavaScriptErrors();

    expect($pocket->refresh()->target_amount)->toBe(9_000)->and($pocket->trashed())->toBeFalse();
});

it('starts a new pocket from the empty state', function (): void {
    $this->actingAs(onboardedUser());

    visit(route('pockets'))->on()->mobile()->resize(390, 844)
        ->click('[data-test="empty-new-pocket"]')
        ->assertVisible('[data-test="pocket-name"]')
        ->assertMissing('[data-test="money-pad"]')
        ->assertNoJavaScriptErrors();
});

it('offers only a deposit on an empty pocket and says why', function (): void {
    $user = onboardedUser();
    $pocket = Pocket::factory()->for($user)->create(['name' => 'Holiday', 'balance' => 0]);
    $this->actingAs($user);

    visit(route('pockets'))->on()->mobile()->resize(390, 844)
        ->assertDisabled('[data-test="pocket-withdraw-'.$pocket->id.'"]')
        ->click('[data-test="pocket-'.$pocket->id.'"]')
        ->assertVisible('[data-test="pocket-sheet"]')
        ->assertAttribute('[data-test="mode-deposit"]', 'aria-checked', 'true')
        ->assertDisabled('[data-test="mode-budget"]')
        ->assertDisabled('[data-test="mode-spend"]')
        ->assertSeeIn('[data-test="empty-note"]', 'The pocket is empty, there is nothing to take out.')
        ->assertNoJavaScriptErrors();
});

it('fits the chosen money move, the amount, the numpad and the button on a short phone, the movements behind their title', function (int $width, int $height): void {
    $user = onboardedUser();
    $pocket = Pocket::factory()->for($user)->create(['name' => 'Holiday', 'balance' => 20_000]);
    $user->pocketMovements()->create(['pocket_id' => $pocket->id, 'amount' => -5_000, 'type' => 'withdraw', 'occurred_on' => now()->toDateString()]);
    $this->actingAs($user);

    $page = visit(route('pockets'))->on()->mobile()->resize($width, $height)
        ->click('[data-test="pocket-deposit-'.$pocket->id.'"]')
        ->assertVisible('[data-test="pocket-sheet"]')
        ->wait(0.5);

    $inView = fn (string $selector): bool => (bool) $page->script("() => { const r = document.querySelector('{$selector}').getBoundingClientRect(); return r.height > 0 && r.top >= 0 && r.bottom <= window.innerHeight }");

    expect($inView('[data-test="mode-deposit"]'))->toBeTrue()
        ->and($inView('[data-test="move-amount"]'))->toBeTrue()
        ->and($inView('[data-test="money-pad"] [data-key="0"]'))->toBeTrue()
        ->and($inView('[data-test="pocket-move"]'))->toBeTrue()
        ->and($inView('[data-test="movements-toggle"]'))->toBeTrue()
        ->and($inView('[data-test="mode-spend"]'))->toBeFalse();

    // Short phones keep the movements folded, tall ones show them.
    expect($inView('[data-test="movements"]'))->toBe($height > 700);

    $page->click('[data-test="mode-deposit"]')
        ->click('[data-test="mode-spend"]')
        ->assertAttribute('[data-test="mode-spend"]', 'aria-checked', 'true')
        ->assertSeeIn('[data-test="mode-spend"]', 'The pocket pays it')
        ->assertMissing('[data-test="mode-deposit"]')
        ->assertNoJavaScriptErrors();

    if ($height <= 700) {
        $page->click('[data-test="movements-toggle"]');
    }

    $page->script("() => document.querySelector('[data-test=movement-amount]').scrollIntoView()");
    $page->assertSeeIn('[data-test="movement-amount"]', "\u{2212}".money(5_000));

    expect($page->script("() => document.querySelector('[data-test=movement-amount]').className"))->toContain('text-danger');
})->with([[393, 852], [393, 659], [375, 667]]);

it('fits the prepayment sheet on a short phone: the whole numpad above the button, the pocket name in full from 393 px', function (int $width, int $height): void {
    // In Hungarian, where the intro takes two lines.
    $user = onboardedUser(['locale' => 'hu']);
    $loan = Loan::factory()->for($user)->create(['name' => 'Home loan']);
    Pocket::factory()->for($user)->create(['name' => 'Előtörlesztési persely', 'balance' => 450_000, 'prepay_step' => 500_000, 'loan_id' => $loan->id]);
    $this->actingAs($user);

    $page = visit(route('pockets'))->on()->mobile()->resize($width, $height)
        ->click('[data-test="prepay-loan-'.$loan->id.'"]')
        ->assertVisible('[data-test="prepay-sheet"]')
        ->assertSeeIn('[data-test="prepay-sheet"]', 'Egyenleg: 450');

    // Polls while the sheet slides up; the keys must end above the sticky footer (with half a pixel of slack).
    $layout = $page->script(<<<'JS'
        async () => {
            const sheet = document.querySelector('[data-test="prepay-sheet"]')
            const measure = () => {
                const lastKey = sheet.querySelector('[data-numpad] [data-key="0"]').getBoundingClientRect()
                const save = sheet.querySelector('[data-test="sheet-save"]').getBoundingClientRect()
                const select = sheet.querySelector('select[data-test="prepay-pocket"]')
                // A select does not report its overflow: measure the chosen name in the select's font against its text room (pr-6 for the chevron).
                const probe = document.createElement('span')
                probe.style.cssText = 'position:absolute;visibility:hidden;white-space:nowrap'
                probe.style.font = getComputedStyle(select).font
                probe.textContent = select.selectedOptions[0].textContent
                document.body.append(probe)
                const nameWidth = probe.getBoundingClientRect().width
                probe.remove()
                return { keysAboveButton: lastKey.bottom <= save.top + 0.5 && save.bottom <= window.innerHeight + 0.5, nameFits: nameWidth <= select.clientWidth - 24 + 0.5 }
            }
            let result = measure()
            for (let i = 0; i < 20 && ! result.keysAboveButton; i++) { await new Promise(r => setTimeout(r, 100)); result = measure() }
            return result
        }
    JS);

    expect($layout['keysAboveButton'])->toBeTrue();
    if ($width > 360) {
        expect($layout['nameFits'])->toBeTrue();
    }
    $page->assertNoJavaScriptErrors();
})->with([
    // At 320 px a long pocket name may still lose a few letters; the keys must fit.
    '320×568' => [320, 568],
    '393×659' => [393, 659],
]);

it('keeps the balance and the tabs on screen when paying a spending opens its fields, and after switching back', function (): void {
    $user = onboardedUser();
    $pocket = Pocket::factory()->for($user)->create(['name' => 'Holiday', 'balance' => 20_000, 'target_amount' => 300_000]);
    $user->pocketMovements()->create(['pocket_id' => $pocket->id, 'amount' => -5_000, 'type' => 'withdraw', 'occurred_on' => now()->toDateString()]);
    $this->actingAs($user);

    $page = visit(route('pockets'))->on()->mobile()->resize(393, 659)
        ->click('[data-test="pocket-deposit-'.$pocket->id.'"]')
        ->assertVisible('[data-test="pocket-sheet"]');

    // Polls a little: the spending fields scroll themselves into view after they render.
    $headShown = <<<'JS'
        async () => {
            await new Promise(r => setTimeout(r, 400))
            const head = document.querySelector('[data-test="pocket-sheet-head"]').getBoundingClientRect()
            const body = document.querySelector('[data-test="pocket-sheet-head"]').parentElement.getBoundingClientRect()
            const balance = document.querySelector('[data-test="pocket-balance"]').getBoundingClientRect()
            return head.top >= body.top - 0.5 && balance.top >= body.top - 0.5 && balance.bottom <= window.innerHeight
        }
    JS;

    $page->click('[data-test="mode-deposit"]')->click('[data-test="mode-spend"]')->assertVisible('[data-test="spend-category"]');
    expect($page->script($headShown))->toBeTrue();

    $page->click('[data-test="mode-spend"]')->click('[data-test="mode-deposit"]');
    expect($page->script($headShown))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
