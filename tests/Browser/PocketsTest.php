<?php

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
