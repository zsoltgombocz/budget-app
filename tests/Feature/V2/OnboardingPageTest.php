<?php

use App\Models\BudgetSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00'));
    $this->user = User::factory()->create();
    BudgetSetting::factory()->for($this->user)->create(['locale' => 'hu', 'onboarded_at' => null]);
    app()->setLocale('hu');
    $this->actingAs($this->user);
});

it('sends new users to the onboarding', function (): void {
    $this->get(route('dashboard'))->assertRedirect(route('onboarding'));
});

it('asks for the income first and will not go on without it', function (): void {
    Livewire::test('pages::onboarding')
        ->assertSee('Mennyi pénz jön be havonta?')
        ->call('next')
        ->assertHasErrors('income')
        ->assertSet('step', 1);
});

it('builds the month from the four answers and goes to the notifications', function (): void {
    Livewire::test('pages::onboarding')
        ->call('setAmount', 'income', '450000')
        ->call('next')
        ->call('next')
        ->call('setAmount', 'line', '150000', 0)
        ->call('setDay', 0, 10)
        ->call('setAmount', 'line', '100000', 1)
        ->call('setAmount', 'savings', '45000')
        ->assertSee('minden hónap 10-én')
        ->assertSee(money(250_000))
        ->call('next')
        ->assertSee('Erre a hónapra:')
        ->assertSee(money(155_000))
        ->assertSee('napi '.money(5_000))
        ->call('finish')
        ->assertRedirect(route('notifications.onboarding'));

    $settings = $this->user->settings()->fresh();

    expect($settings->onboarded_at)->not->toBeNull()
        ->and($settings->income)->toBe(450_000)
        ->and($this->user->budgetLines()->count())->toBe(3)
        ->and($this->user->pockets()->sole()->name)->toBe('Vésztartalék');
});

it('asks for the payday when the month starts on payday', function (): void {
    Livewire::test('pages::onboarding')
        ->call('setAmount', 'income', '450000')
        ->call('next')
        ->set('periodMode', 'payday')
        ->call('next')
        ->assertHasErrors('paydayDay')
        ->set('paydayDay', 10)
        ->call('next')
        ->assertSet('step', 3);
});

it('takes one total instead of lines', function (): void {
    Livewire::test('pages::onboarding')
        ->call('setAmount', 'income', '450000')
        ->set('step', 3)
        ->set('single', true)
        ->call('setAmount', 'singleTotal', '250000')
        ->set('step', 4)
        ->assertSee(money(200_000));
});

it('adds an other fixed expense', function (): void {
    Livewire::test('pages::onboarding')
        ->set('step', 3)
        ->call('addLine', 'Közös kassza')
        ->assertSee('Közös kassza');
});

it('keeps the answers as a draft and resumes them', function (): void {
    Livewire::test('pages::onboarding')->call('setAmount', 'income', '450000')->call('next');

    Livewire::test('pages::onboarding')->assertSet('step', 2)->assertSet('income', '450000');
});
