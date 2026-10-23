<?php

use App\Models\BudgetSetting;
use App\Models\User;

it('goes through the four steps with the numpad and opens Today', function (): void {
    $user = User::factory()->create();
    BudgetSetting::factory()->for($user)->create(['locale' => 'en', 'onboarded_at' => null]);
    $this->actingAs($user);

    $page = visit(route('onboarding'))->on()->mobile()->resize(390, 844);

    $page->assertSee('How much money comes in a month?')
        ->click('[data-test="income"]')
        ->click('[data-key="4"]:visible')->click('[data-key="5"]:visible')->click('[data-key="0"]:visible')->click('[data-key="000"]:visible')
        ->click('[data-test="numpad-done"]:visible')
        ->click('[data-test="onboarding-next"]')
        ->waitForText('When does your month start?')
        ->click('[data-test="onboarding-next"]')
        ->waitForText('What goes out every month on its own?')
        ->click('[data-test="line-amount"] >> nth=0')
        ->click('[data-key="1"]:visible')->click('[data-key="5"]:visible')->click('[data-key="0"]:visible')->click('[data-key="000"]:visible')
        ->click('[data-test="numpad-done"]:visible')
        ->waitForText('150,000')
        ->click('[data-test="onboarding-next"]')
        ->waitForText('For this month:')
        ->assertSee('300,000')
        ->assertNoJavaScriptErrors();
});

it('picks up where it was after a reload', function (): void {
    $user = User::factory()->create();
    BudgetSetting::factory()->for($user)->create(['locale' => 'en', 'onboarded_at' => null]);
    $this->actingAs($user);

    visit(route('onboarding'))->on()->mobile()
        ->click('[data-test="income"]')
        ->click('[data-key="5"]:visible')->click('[data-key="000"]:visible')
        ->click('[data-test="numpad-done"]:visible')
        ->click('[data-test="onboarding-next"]')
        ->waitForText('When does your month start?');

    visit(route('onboarding'))->on()->mobile()->assertSee('When does your month start?')->assertNoJavaScriptErrors();
});
