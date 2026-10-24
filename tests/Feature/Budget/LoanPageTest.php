<?php

use App\Enums\Plan;
use App\Enums\PrepayMode;
use App\Models\Loan;
use App\Models\Pocket;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = onboardedUser();
    $this->actingAs($this->user);
    $this->loan = Loan::factory()->for($this->user)->create([
        'principal_balance' => 3_200_000, 'installment' => 72_600, 'insurance' => 0,
        'thm' => 9.9, 'remaining_months' => 55, 'prepay_mode' => PrepayMode::ReduceTerm,
    ]);
    Pocket::factory()->for($this->user)->create(['balance' => 450_000, 'prepay_step' => 500_000, 'loan_id' => $this->loan->id]);
});

it('locks the loan planner without Plusz', function (): void {
    $this->get(route('loan', $this->loan))
        ->assertOk()
        ->assertSee('data-test="plusz-lock"', false)
        ->assertSee('Switch to Plusz');
});

it('plans the loan-free date with Plusz and moves it with the monthly saving', function (): void {
    $this->user->forceFill(['plan' => Plan::Plus])->save();

    $page = Livewire::test('pages::loan', ['loan' => $this->loan])
        ->assertDontSee('data-test="plusz-lock"', false)
        ->assertSee('Loan-free by')
        ->set('monthly', 0)
        ->assertSee('As the bank says');

    $page->set('monthly', 50_000)->assertSee('months sooner');
});

it('shows the installment a year from now when the bank lowered it last time', function (): void {
    $this->user->forceFill(['plan' => Plan::Plus])->save();
    $this->loan->update(['prepay_mode' => PrepayMode::ReduceInstallment]);

    Livewire::test('pages::loan', ['loan' => $this->loan])->set('monthly', 50_000)->assertSee('Your installment a year from now');
});

it('does not open another user\'s loan', function (): void {
    $this->get(route('loan', Loan::factory()->create()))->assertNotFound();
});
