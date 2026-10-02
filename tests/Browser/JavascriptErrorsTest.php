<?php

use App\Models\User;

it('has no JavaScript errors on the main screens', function (): void {
    $this->actingAs(onboardedUser());

    foreach (['dashboard', 'plan', 'pockets', 'month', 'settings', 'budget.edit', 'appearance.edit', 'notifications.edit'] as $route) {
        visit(route($route))->on()->mobile()->assertNoJavascriptErrors();
    }
});

it('has no JavaScript errors in the setup wizard', function (): void {
    $this->actingAs(User::factory()->create());

    visit(route('onboarding'))->on()->mobile()->assertNoJavascriptErrors();
});
