<?php

use App\Models\BudgetSetting;
use App\Models\User;

it('resumes the wizard after a reload and can start over', function (): void {
    $user = User::factory()->create();
    BudgetSetting::factory()->for($user)->create(['locale' => 'en', 'onboarded_at' => null, 'intro_seen_at' => null]);
    $this->actingAs($user);

    $page = visit(route('onboarding'))->on()->mobile();

    $page->assertSee('Tap and type it in')
        ->click('[data-test="onboarding-income"]')
        ->click('[data-key="4"]:visible')->click('[data-key="5"]:visible')->click('[data-key="0"]:visible')->click('[data-key="000"]:visible')
        ->click('[data-test="numpad-done"]:visible')
        ->click('[data-test="onboarding-next"]')
        ->waitForText('When does your month start?')
        ->assertNoJavaScriptErrors();

    $page = visit(route('onboarding'))->on()->mobile();

    $page->assertSee('Picking up where you left off.')
        ->assertSee('When does your month start?')
        ->click('[data-test="onboarding-restart"]')
        ->click('[data-test="confirm-ok"]')
        ->waitForText('How much do you earn?')
        ->assertDontSee('Picking up where you left off.')
        ->assertNoJavaScriptErrors();

    expect($user->settings()->refresh()->onboarding_draft)->toBeNull();
});
