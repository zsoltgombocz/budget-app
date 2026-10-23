<?php

use App\Enums\LineType;
use App\Enums\Plan;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\User;

it('has no JavaScript errors on the main screens', function (): void {
    $this->actingAs(onboardedUser());

    foreach (['dashboard', 'month', 'companion', 'settings', 'budget.edit', 'companion.edit', 'help', 'subscription', 'appearance.edit', 'notifications.edit'] as $route) {
        visit(route($route))->on()->mobile()->assertNoJavascriptErrors();
    }
});

it('has no JavaScript errors in the setup wizard', function (): void {
    $this->actingAs(User::factory()->create());

    visit(route('onboarding'))->on()->mobile()->assertNoJavascriptErrors();
});

it('has no JavaScript errors in the loan planner', function (): void {
    $user = onboardedUser();
    $user->forceFill(['plan' => Plan::Plus])->save();
    $line = BudgetLine::factory()->for($user)->for(Category::factory()->for($user)->create(['type' => LineType::Loan]))->create(['amount' => 72_600]);
    $this->actingAs($user);

    visit(route('loan', $line))->on()->mobile()->assertNoJavascriptErrors()->assertSee('New loan');
});
