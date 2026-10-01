<?php

use App\Models\DayMark;
use App\Models\Transaction;

it('records a spending from the + button with the numpad', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('dashboard'))->on()->mobile()->resize(390, 844);

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
        ->assertSee('47,500')
        ->assertNoJavaScriptErrors();

    expect(Transaction::query()->sole()->amount)->toBe(12_500);
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
        ->click('[data-test="empty-state"] [data-test="no-spend"]')
        ->waitForText("You didn't spend today")
        ->click('[data-test="undo-no-spend"]')
        ->wait(1);

    expect(DayMark::query()->count())->toBe(0);
});
