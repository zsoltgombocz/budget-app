<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Services\PeriodService;

beforeEach(function (): void {
    $this->user = onboardedUser();
    $this->actingAs($this->user);
    $period = resolve(PeriodService::class)->current($this->user);
    $fuel = Category::query()->where('name', 'Fuel')->firstOrFail();
    $this->spending = Transaction::factory()->for($this->user)->for($period)->for($fuel)->create(['amount' => 12_000, 'note' => 'Shell']);
});

it('corrects a spending on the month page with the numpad', function (): void {
    visit(route('month'))->on()->mobile()->resize(390, 844)
        ->click('[data-test="transaction"]')
        ->assertVisible('[data-test="transaction-sheet"]')
        ->click('[data-test="edit-amount"]')
        ->click('[data-key="del"]:visible')->click('[data-key="del"]:visible')->click('[data-key="del"]:visible')
        ->click('[data-key="del"]:visible')->click('[data-key="del"]:visible')
        ->click('[data-key="8"]:visible')->click('[data-key="5"]:visible')->click('[data-key="0"]:visible')->click('[data-key="0"]:visible')
        ->click('[data-test="pad-done"]:visible')
        ->select('[data-test="edit-category"]', 'Groceries')
        ->fill('[data-test="edit-note"]', 'Aldi')
        ->click('[data-test="save-transaction"]')
        ->waitForText('Spending updated.')
        ->assertSee('8,500')
        ->assertSee('Aldi')
        ->assertNoJavaScriptErrors();

    $this->spending->refresh();

    expect($this->spending->amount)->toBe(8_500)
        ->and($this->spending->note)->toBe('Aldi')
        ->and($this->spending->category->name)->toBe('Groceries');
});

it('deletes a spending and brings it back from the toast', function (): void {
    $page = visit(route('month'))->on()->mobile()->resize(390, 844);

    $page->click('[data-test="transaction"]')
        ->click('[data-test="delete-transaction"]')
        ->waitForText('Entry removed.')
        ->assertDontSee('Shell');

    expect(Transaction::query()->count())->toBe(0);

    $page->click('Undo')
        ->waitForText('Spending restored.')
        ->assertSee('Shell')
        ->assertNoJavaScriptErrors();

    expect(Transaction::query()->sole()->client_uuid)->toBe($this->spending->client_uuid);
});

it('keeps the fixed items folded into one row until it is opened, and remembers that for the session', function (): void {
    $page = visit(route('month'))->on()->mobile()->resize(390, 844);

    $page->assertSeeIn('[data-test="fixed-summary"]', '0 of 1 done')
        ->assertAttribute('[data-test="fixed-summary"]', 'aria-expanded', 'false')
        ->assertMissing('[data-test="fixed-item"]:visible')
        ->click('[data-test="fixed-summary"]')
        ->assertAttribute('[data-test="fixed-summary"]', 'aria-expanded', 'true')
        ->assertVisible('[data-test="fixed-item"]')
        ->click('[data-test="fixed-item"]')
        ->waitForText('Marked as done.')
        ->assertSeeIn('[data-test="fixed-summary"]', '1 of 1 done')
        ->assertAttribute('[data-test="fixed-item"]', 'aria-checked', 'true')
        ->assertVisible('[data-test="fixed-item"]');

    $page->click('Undo')
        ->waitForText('0 of 1 done')
        ->assertAttribute('[data-test="fixed-item"]', 'aria-checked', 'false');

    // Still open after a reload in the same browser session.
    $page->refresh()
        ->assertAttribute('[data-test="fixed-summary"]', 'aria-expanded', 'true')
        ->assertVisible('[data-test="fixed-item"]')
        ->click('[data-test="fixed-summary"]')
        ->assertMissing('[data-test="fixed-item"]:visible')
        ->assertNoJavaScriptErrors();
});

it('titles the edit sheet with the category picked before saving', function (): void {
    visit(route('month'))->on()->mobile()->resize(390, 844)
        ->click('[data-test="transaction"]')
        ->assertSeeIn('[data-test="transaction-sheet"] [data-test="sheet-title"]', 'Fuel')
        ->select('[data-test="edit-category"]', 'Groceries')
        ->assertSeeIn('[data-test="transaction-sheet"] [data-test="sheet-title"]', 'Groceries')
        ->assertNoJavaScriptErrors();
});
