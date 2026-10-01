<?php

use App\Models\Transaction;

it('records a spending with two taps on a phone', function (): void {
    $this->actingAs(onboardedUser());

    $page = visit(route('entry'))->on()->iPhone14Pro();

    $page->assertSee('What did you spend on?')
        ->click('Fuel')                 // tap 1: category
        ->keys('[data-test="save-entry"]', ['1', '2', '5', '0', '0'])
        ->assertSee('12,500')
        ->click('[data-test="save-entry"]') // tap 2: save
        ->waitForText('Undo')
        ->assertNoJavaScriptErrors();

    expect(Transaction::query()->sole()->amount)->toBe(12_500);
});

it('undoes the last entry from the toast', function (): void {
    $this->actingAs(onboardedUser());

    visit(route('entry'))->on()->iPhone14Pro()
        ->click('Groceries')
        ->click('5')
        ->click('000')
        ->click('[data-test="save-entry"]')
        ->waitForText('Undo')
        ->click('Undo')
        ->waitForText('Entry removed.');

    expect(Transaction::query()->count())->toBe(0);
});
