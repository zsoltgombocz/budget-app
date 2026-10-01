<?php

use App\Models\User;

test('guests are redirected to the login page', function (): void {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('users without a plan are sent to onboarding', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertRedirect(route('onboarding'));
});

test('onboarded users can visit the dashboard', function (): void {
    $this->actingAs(onboardedUser());

    $this->get(route('dashboard'))->assertOk();
});
