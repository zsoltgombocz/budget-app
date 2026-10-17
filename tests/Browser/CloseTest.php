<?php

use App\Models\Category;
use App\Models\Period;
use App\Models\Pocket;
use App\Models\Transaction;

it('fits the closing wizard on a 320 px screen: names above numbers, the split boxes stacked', function (): void {
    $user = onboardedUser();
    Pocket::factory()->for($user)->create(['name' => 'Reserve', 'is_reserve' => true, 'balance' => 10_000]);
    $period = Period::factory()->for($user)->create(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'income_planned' => 500_000]);
    $category = Category::query()->where('name', 'Groceries')->firstOrFail();
    $category->update(['name' => 'Groceries and household shopping']);
    Transaction::factory()->for($user)->for($period)->for($category)->create(['amount' => 12_345_678, 'occurred_on' => '2026-09-15']);
    $this->actingAs($user);

    $noScroll = '() => document.documentElement.scrollWidth <= document.documentElement.clientWidth';

    $page = visit(route('close', $period))->on()->mobile()->resize(320, 568)
        ->assertSee('Groceries and household shopping')
        ->assertSee('overspent')
        ->wait(0.6);

    expect($page->script($noScroll))->toBeTrue()
        ->and($page->script("() => document.querySelector('[data-test=close-row] [role=rowheader]').getBoundingClientRect().width > 200"))->toBeTrue();

    $page->click('[data-test="close-next"]')->assertSee('The app only keeps records')->wait(0.6);

    expect($page->script($noScroll))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
