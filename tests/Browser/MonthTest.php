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
