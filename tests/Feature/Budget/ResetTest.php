<?php

use App\Models\Transaction;
use App\Services\PeriodService;
use Livewire\Livewire;

it('resets the budget and restarts onboarding', function (): void {
    $user = onboardedUser();
    $this->actingAs($user);
    $period = resolve(PeriodService::class)->current($user);
    Transaction::factory()->for($user)->for($period)->for($user->categories()->first())->create();
    $user->pockets()->create(['name' => 'Reserve', 'is_reserve' => true, 'balance' => 10_000]);
    $user->updatePushSubscription('https://push.example.com/x', 'k', 't');

    Livewire::test('pages::settings.budget')->call('resetBudget')->assertRedirect(route('onboarding'));

    $user->refresh();

    expect($user->categories()->withTrashed()->count())->toBe(0)
        ->and($user->periods()->count())->toBe(0)
        ->and($user->transactions()->count())->toBe(0)
        ->and($user->pockets()->count())->toBe(0)
        ->and($user->settings()->isOnboarded())->toBeFalse()
        ->and($user->pushSubscriptions()->count())->toBe(1);

    $this->get(route('dashboard'))->assertRedirect(route('onboarding'));
});
