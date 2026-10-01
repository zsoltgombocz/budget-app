<?php

use App\Models\BudgetLine;
use App\Models\Category;

it('shows the main number and the top 4 budgets without scrolling at 375 px', function (): void {
    $user = onboardedUser();

    foreach (['Coffee', 'Pets'] as $name) {
        $category = Category::factory()->for($user)->create(['name' => $name, 'type' => 'variable']);
        BudgetLine::factory()->for($user)->for($category)->create(['amount' => 10_000]);
    }

    $this->actingAs($user);

    $page = visit(route('dashboard'))->on()->mobile()->resize(375, 667);

    $page->assertSee('Expected leftover at period end')->assertNoJavaScriptErrors();

    $fits = $page->script(<<<'JS'
        () => {
            const tabBar = document.querySelector('nav[aria-label="Main navigation"]').getBoundingClientRect().top;
            const main = document.querySelector('[data-test="expected-leftover"]').getBoundingClientRect();
            const bars = [...document.querySelectorAll('[data-test="categories"] [role="progressbar"]')].slice(0, 4);
            return window.innerWidth === 375
                && bars.length === 4
                && main.bottom <= tabBar
                && bars.every(bar => bar.getBoundingClientRect().bottom <= tabBar)
                && document.documentElement.scrollWidth <= window.innerWidth;
        }
    JS);

    expect($fits)->toBeTrue();
});
