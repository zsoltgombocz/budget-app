<?php

use App\Models\DayMark;
use App\Models\Transaction;

it('records a spending from the + button with the numpad', function (): void {
    $this->actingAs(onboardedUser());

    // From the plan: on Today the floating + stays away while the card's own Record button is on screen.
    $page = visit(route('plan'))->on()->mobile()->resize(390, 844);

    $page->click('[data-test="tab-entry"]')
        ->click('[data-test="category-grid"] button:has-text("Fuel")')
        ->click('[data-test="entry-sheet"] [data-key="1"]')
        ->click('[data-test="entry-sheet"] [data-key="2"]')
        ->click('[data-test="entry-sheet"] [data-key="5"]')
        ->click('[data-test="entry-sheet"] [data-key="0"]')
        ->click('[data-test="entry-sheet"] [data-key="0"]')
        ->assertSee('12,500')
        ->click('[data-test="save-entry"]')
        ->waitForText('Undo')
        ->assertNoJavaScriptErrors();

    expect(Transaction::query()->sole()->amount)->toBe(12_500);
    visit(route('dashboard'))->on()->mobile()->resize(390, 844)->assertSee('47,500');
});

it('undoes the entry from the toast', function (): void {
    $this->actingAs(onboardedUser());

    visit(route('entry'))->on()->mobile()->resize(390, 844)
        ->click('[data-test="category-grid"] button:has-text("Groceries")')
        ->click('[data-test="entry-sheet"] [data-key="5"]')
        ->click('[data-test="entry-sheet"] [data-key="000"]')
        ->click('[data-test="save-entry"]')
        ->waitForText('Undo')
        ->click('Undo')
        ->waitForText('Entry removed.');

    expect(Transaction::query()->count())->toBe(0);
});

it('marks and unmarks a no-spend day from the dashboard', function (): void {
    $this->actingAs(onboardedUser());

    visit(route('dashboard'))->on()->mobile()->resize(390, 844)
        ->click('[data-test="daily-allowance"] [data-test="no-spend"]')
        ->waitForText("You didn't spend today")
        // It stays in the daily card as a marked row, undoable in place.
        ->assertVisible('[data-test="daily-allowance"] [data-test="no-spend-marked"]')
        ->click('[data-test="undo-no-spend"]')
        ->assertVisible('[data-test="daily-allowance"] [data-test="no-spend"]');

    expect(DayMark::query()->count())->toBe(0);
});
