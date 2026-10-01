<?php

use App\Enums\PrepayMode;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\Loan;
use App\Models\Pocket;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = onboardedUser();
    $this->actingAs($this->user);
});

it('lists pockets and loans with an explanation when empty', function (): void {
    $this->get(route('pockets'))->assertOk()->assertSee('Pockets collect money for a goal');
});

it('creates a pocket and moves money in and out', function (): void {
    Livewire::test('pages::pockets')
        ->call('editPocket')
        ->set('pocketName', 'Holiday')
        ->set('pocketTarget', '300000')
        ->call('savePocket')
        ->assertHasNoErrors();

    $pocket = Pocket::query()->where('name', 'Holiday')->sole();

    Livewire::test('pages::pockets')
        ->call('editPocket', $pocket->id)
        ->set('moveAmount', '50000')->call('movePocketMoney', 1)
        ->set('moveAmount', '20000')->call('movePocketMoney', -1);

    expect($pocket->refresh()->balance)->toBe(30_000)
        ->and($pocket->target_amount)->toBe(300_000)
        ->and($pocket->movements()->count())->toBe(2);
});

it('keeps a single reserve pocket', function (): void {
    $old = Pocket::factory()->for($this->user)->create(['is_reserve' => true]);

    Livewire::test('pages::pockets')
        ->call('editPocket')
        ->set('pocketName', 'New reserve')
        ->set('pocketIsReserve', true)
        ->call('savePocket');

    expect($old->refresh()->is_reserve)->toBeFalse()
        ->and(Pocket::query()->where('is_reserve', true)->value('name'))->toBe('New reserve');
});

it('saves a loan and syncs its plan line', function (): void {
    $loan = Loan::factory()->for($this->user)->create(['installment' => 80_000, 'insurance' => 2_000]);
    $category = Category::factory()->for($this->user)->create(['type' => 'loan']);
    $line = BudgetLine::factory()->for($this->user)->for($category)->create(['amount' => 82_000, 'loan_id' => $loan->id]);

    Livewire::test('pages::pockets')
        ->call('editLoan', $loan->id)
        ->set('loanInstallment', '75000')
        ->set('loanThm', '11,5')
        ->call('saveLoan')
        ->assertHasNoErrors();

    expect($line->refresh()->amount)->toBe(77_000)
        ->and($loan->refresh()->thm)->toBe(11.5);
});

it('records a prepayment from the loan card', function (): void {
    $loan = Loan::factory()->for($this->user)->create([
        'principal_balance' => 2_000_000, 'installment' => 50_000, 'insurance' => 0,
        'thm' => null, 'remaining_months' => null, 'prepay_mode' => PrepayMode::ReduceInstallment,
    ]);
    $pocket = Pocket::factory()->for($this->user)->create(['balance' => 500_000, 'prepay_step' => 500_000, 'loan_id' => $loan->id]);

    Livewire::test('pages::pockets')
        ->call('startPrepayment', $loan->id)
        ->assertSet('prepayPocketId', $pocket->id)
        ->assertSet('prepayAmount', '500000')
        ->call('prepay')
        ->assertHasNoErrors();

    expect($loan->refresh()->principal_balance)->toBe(1_500_000)
        ->and($loan->installment)->toBe(37_500)
        ->and($pocket->refresh()->balance)->toBe(0);
});

it('refuses a prepayment larger than the pocket', function (): void {
    $loan = Loan::factory()->for($this->user)->create();
    $pocket = Pocket::factory()->for($this->user)->create(['balance' => 1_000]);

    Livewire::test('pages::pockets')
        ->call('startPrepayment', $loan->id)
        ->set('prepayAmount', '5000')
        ->set('prepayPocketId', $pocket->id)
        ->call('prepay')
        ->assertHasErrors('amount');
});
