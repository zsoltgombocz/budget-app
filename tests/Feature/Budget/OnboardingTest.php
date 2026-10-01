<?php

use App\Enums\Currency;
use App\Enums\PeriodMode;
use App\Models\User;
use Database\Seeders\CategoryTemplateSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(CategoryTemplateSeeder::class);
});

it('sends new users to the wizard', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertRedirect(route('onboarding'));
    $this->get(route('onboarding'))->assertOk();
});

it('builds the plan from the wizard answers', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::onboarding')
        ->set('income', '609 000')->call('next')
        ->set('periodMode', 'payday')->set('paydayDay', 31)->call('next')
        ->set('currency', 'HUF')->call('next')
        ->set('templateKey', 'with_loan')->call('next')
        ->assertSet('step', 5)
        ->set('amounts.0', '180000')
        ->set('amounts.8', '87549')
        ->call('next')
        ->set('reserveTarget', '300000')
        ->set('reservePct', 50)
        ->set('surplusTarget', 'investment')
        ->call('finish')
        ->assertHasNoErrors()
        ->assertRedirect(route('notifications.onboarding'));

    $settings = $user->refresh()->settings();

    expect($settings->isOnboarded())->toBeTrue()
        ->and($settings->income)->toBe(609_000)
        ->and($settings->period_mode)->toBe(PeriodMode::Payday)
        ->and($settings->payday_day)->toBe(31)
        ->and($settings->currency)->toBe(Currency::HUF)
        ->and($settings->reserve_pct)->toBe(50)
        ->and($settings->surplus_account_id)->not->toBeNull()
        ->and($user->budgetLines()->sum('amount'))->toBe(267_549)
        ->and($user->pockets()->where('is_reserve', true)->value('target_amount'))->toBe(300_000)
        ->and($user->periods()->count())->toBe(1);
});

it('validates each step before moving on', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::onboarding')
        ->set('income', 'sok')
        ->call('next')
        ->assertHasErrors('income')
        ->assertSet('step', 1);
});

it('creates a reserve pocket for the empty template when a target is given', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::onboarding')
        ->set('income', '1000')->call('next')
        ->set('periodMode', 'calendar')->call('next')
        ->set('currency', 'EUR')->call('next')
        ->set('templateKey', 'empty')->call('next')
        ->call('next')
        ->set('reserveTarget', '2500.50')
        ->set('surplusTarget', 'pocket')
        ->call('finish')
        ->assertHasNoErrors();

    expect($user->settings()->refresh()->income)->toBe(100_000)
        ->and($user->pockets()->where('is_reserve', true)->value('target_amount'))->toBe(250_050)
        ->and($user->settings()->surplus_pocket_id)->not->toBeNull();
});

it('skips the wizard once onboarded', function (): void {
    $this->actingAs(onboardedUser());

    Livewire::test('pages::onboarding')->assertRedirect(route('dashboard'));
});

it('skips template lines that were switched off', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::onboarding')
        ->set('income', '500000')->call('next')
        ->set('periodMode', 'calendar')->call('next')
        ->call('next')
        ->set('templateKey', 'couple')->call('next')
        ->set('included.1', false)
        ->call('next')
        ->call('finish')
        ->assertHasNoErrors();

    expect($user->categories()->pluck('name'))->not->toContain('Utilities')
        ->and($user->categories()->count())->toBe(9);
});

it('shows a hint for every template line', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::onboarding')
        ->set('income', '500000')->call('next')->call('next')->call('next')
        ->set('templateKey', 'couple')->call('next')
        ->assertSee('Leave 0 if they are paid from a joint account.');
});
