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

it('fits every kind of step on a 320 px screen with the footer in view', function (array $state): void {
    $user = User::factory()->create();
    BudgetSetting::factory()->for($user)->create(['locale' => 'en', 'onboarded_at' => null, 'intro_seen_at' => null]);
    $this->actingAs($user);

    $page = visit(route('onboarding'))->on()->mobile()->resize(320, 568);

    $result = $page->script('async () => {
        const wizard = Livewire.all().find(component => component.name.includes("onboarding")).$wire
        for (const [key, value] of Object.entries('.json_encode($state).')) await wizard.$set(key, value)
        await new Promise(resolve => setTimeout(resolve, 300))
        const next = document.querySelector("[data-test=onboarding-next]").getBoundingClientRect()
        return {
            noSideScroll: document.documentElement.scrollWidth <= document.documentElement.clientWidth,
            nextInView: next.top >= 0 && next.bottom <= window.innerHeight && next.width > 0,
        }
    }');

    expect($result)->toBe(['noSideScroll' => true, 'nextInView' => true]);
    $page->assertNoJavaScriptErrors();
})->with([
    'income' => [[]],
    'fixed items' => [['income' => '450000', 'step' => 4, 'amounts.0' => '180000']],
    'loan' => [['income' => '450000', 'step' => 6, 'hasLoan' => true]],
    'reserve' => [['income' => '450000', 'step' => 8]],
    'leftover' => [['income' => '450000', 'step' => 9]],
]);
