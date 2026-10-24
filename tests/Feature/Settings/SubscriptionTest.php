<?php

use App\Enums\Plan;
use Livewire\Livewire;

it('shows the plan, what is in Plusz and the prices, and starts the 14-day offer after the trial', function (): void {
    $user = onboardedUser();
    $this->actingAs($user);

    $this->get(route('subscription'))->assertOk()
        ->assertSee('Trial')
        ->assertSee('What is in Plusz?')
        ->assertDontSee('data-test="plus-offer"', false);

    $this->travel(31)->days();

    Livewire::test('pages::settings.subscription')
        ->assertSee('data-test="plus-offer"', false)
        ->call('startOffer')
        ->assertDispatched('app-toast');

    expect($user->fresh()->currentPlan())->toBe(Plan::PlusTrial);
});
