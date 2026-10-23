<?php

use App\Models\Category;
use App\Models\Period;
use App\Models\Pocket;
use App\Models\Transaction;

it('fits the closing on a 320 px screen and closes with the leftover on a goal', function (): void {
    $user = onboardedUser();
    $goal = Pocket::factory()->for($user)->create(['name' => 'Holiday and a long weekend away', 'balance' => 10_000]);
    $period = Period::factory()->for($user)->create(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'income_planned' => 500_000]);
    $category = Category::query()->where('name', 'Groceries')->firstOrFail();
    Transaction::factory()->for($user)->for($period)->for($category)->create(['amount' => 100_000, 'occurred_on' => '2026-09-15']);
    $this->actingAs($user);

    $page = visit(route('close', $period))->on()->mobile()->resize(320, 568)
        ->assertSee('was left.')
        ->wait(0.5);

    expect($page->script('() => document.documentElement.scrollWidth <= document.documentElement.clientWidth'))->toBeTrue();

    $page->click('[data-test="close-done"]')->waitForText('Undo');

    expect($goal->refresh()->balance)->toBe(210_000);
    $page->assertNoJavaScriptErrors();
});

it('splits the leftover on the numpad', function (): void {
    $user = onboardedUser();
    $goal = Pocket::factory()->for($user)->create(['name' => 'Holiday', 'balance' => 0]);
    $period = Period::factory()->for($user)->create(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'income_planned' => 500_000]);
    $this->actingAs($user);

    visit(route('close', $period))->on()->mobile()->resize(390, 844)
        ->click('[data-test="split"]')
        ->click('[data-test="close-editor"] [data-key="5"]')
        ->click('[data-test="close-editor"] [data-key="000"]')
        ->click('[data-test="numpad-done"]')
        ->waitForText('goes into October')
        ->assertNoJavaScriptErrors();
});
