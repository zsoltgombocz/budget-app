<?php

use App\Actions\Budget\RecordPrepayment;
use App\Actions\Budget\RecordTransaction;
use App\Enums\PeriodStatus;
use App\Enums\PocketMovementType;
use App\Enums\PrepayMode;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\Period;
use App\Models\Pocket;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PeriodCloser;
use App\Services\PeriodService;
use App\Services\PlanService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 18:00', 'Europe/Budapest'));
    $this->user = onboardedUser();
    $this->actingAs($this->user);
    $this->period = resolve(PeriodService::class)->current($this->user);
    $this->fuel = Category::query()->where('name', 'Fuel')->firstOrFail();
    $this->groceries = Category::query()->where('name', 'Groceries')->firstOrFail();
    $this->reserve = Pocket::factory()->for($this->user)->create(['name' => 'Reserve', 'is_reserve' => true, 'balance' => 80_000, 'target_amount' => 100_000]);
    $this->savings = Pocket::factory()->for($this->user)->create(['name' => 'Savings']);
    $this->user->settings()->update(['surplus_pocket_id' => $this->savings->id, 'reserve_pct' => 100]);
});

function spend(User $user, Period $period, Category $category, int $amount): void
{
    Transaction::factory()->for($user)->for($period)->for($category)->create(['amount' => $amount, 'occurred_on' => '2026-10-15']);
}

it('previews actual vs plan with deviations highlighted', function (): void {
    spend($this->user, $this->period, $this->fuel, 70_000);
    spend($this->user, $this->period, $this->groceries, 80_000);

    $preview = resolve(PeriodCloser::class)->preview($this->user, $this->period);
    $rows = collect($preview->categories)->keyBy('name');

    expect($rows['Rent']['actual'])->toBe(200_000)
        ->and($rows['Fuel']['diff'])->toBe(10_000)
        ->and($rows['Fuel']['deviates'])->toBeTrue()
        ->and($rows['Groceries']['deviates'])->toBeTrue()
        ->and($preview->actualTotal)->toBe(350_000)
        ->and($preview->leftover())->toBe(150_000)
        ->and($preview->allocation->toReserve)->toBe(20_000)
        ->and($preview->allocation->toSurplus)->toBe(130_000);
});

it('closes the period, updates pockets and opens the next one', function (): void {
    spend($this->user, $this->period, $this->fuel, 50_000);
    spend($this->user, $this->period, $this->groceries, 90_000);

    $close = resolve(PeriodCloser::class)->close($this->user, $this->period, 520_000);

    expect($close->leftover)->toBe(180_000)
        ->and($close->to_reserve)->toBe(20_000)
        ->and($close->to_invest)->toBe(160_000)
        ->and($this->period->refresh()->status)->toBe(PeriodStatus::Closed)
        ->and($this->period->income_actual)->toBe(520_000)
        ->and($this->reserve->refresh()->balance)->toBe(100_000)
        ->and($this->savings->refresh()->balance)->toBe(160_000)
        ->and($this->user->periods()->whereDate('starts_on', '2026-11-01')->value('status'))->toBe(PeriodStatus::Open);
});

it('covers a deficit from the reserve', function (): void {
    spend($this->user, $this->period, $this->fuel, 400_000);

    $close = resolve(PeriodCloser::class)->close($this->user, $this->period);

    // 500 000 income − (200 000 rent + 400 000 fuel) = −100 000; the reserve has 80 000.
    expect($close->leftover)->toBe(-100_000)
        ->and($close->from_reserve)->toBe(80_000)
        ->and($close->breakdown['allocation']['uncovered'])->toBe(20_000)
        ->and($this->reserve->refresh()->balance)->toBe(0);
});

it('moves planned pocket savings into their pockets', function (): void {
    $category = Category::factory()->for($this->user)->create(['name' => 'Holiday', 'type' => 'sinking']);
    BudgetLine::factory()->for($this->user)->for($category)->create(['amount' => 25_000, 'pocket_id' => $this->savings->id]);

    resolve(PeriodCloser::class)->close($this->user, $this->period);

    expect($this->savings->movements()->where('type', PocketMovementType::Deposit)->where('note', 'Monthly saving')->value('amount'))->toBe(25_000);
});

it('keeps the closed period\'s snapshot when the plan changes later', function (): void {
    resolve(PeriodCloser::class)->close($this->user, $this->period);
    $before = resolve(PlanService::class)->summaryFor($this->period->refresh());

    BudgetLine::query()->whereRelation('category', 'name', 'Rent')->update(['amount' => 999_000]);
    BudgetLine::query()->whereRelation('category', 'name', 'Fuel')->delete();

    $after = resolve(PlanService::class)->summaryFor($this->period->refresh());

    expect($after)->toEqual($before)
        ->and($after->totalExpenses)->toBe(350_000);
});

it('cannot close twice or record into a closed period', function (): void {
    resolve(PeriodCloser::class)->close($this->user, $this->period);

    expect(fn () => resolve(PeriodCloser::class)->close($this->user, $this->period->refresh()))->toThrow(ValidationException::class)
        ->and(fn () => resolve(RecordTransaction::class)->handle($this->user, $this->fuel->id, 1_000, CarbonImmutable::parse('2026-10-20')))->toThrow(ValidationException::class);
});

it('lowers next month\'s installment after prepaying from a full 500k pocket', function (): void {
    $loan = $this->user->loans()->create([
        'name' => 'Personal loan', 'principal_balance' => 4_000_000, 'installment' => 88_978, 'insurance' => 2_549,
        'thm' => 12.0, 'remaining_months' => 60, 'prepay_mode' => PrepayMode::ReduceInstallment,
    ]);
    $loanCategory = Category::factory()->for($this->user)->create(['name' => 'Loan', 'type' => 'loan']);
    BudgetLine::factory()->for($this->user)->for($loanCategory)->create(['amount' => 91_527, 'loan_id' => $loan->id]);

    $fund = Pocket::factory()->for($this->user)->create(['name' => 'Prepayment fund', 'balance' => 450_000, 'prepay_step' => 500_000, 'loan_id' => $loan->id]);
    $fundCategory = Category::factory()->for($this->user)->create(['name' => 'Prepayment fund', 'type' => 'sinking']);
    BudgetLine::factory()->for($this->user)->for($fundCategory)->create(['amount' => 50_000, 'pocket_id' => $fund->id]);

    $close = resolve(PeriodCloser::class)->close($this->user, $this->period);
    expect($close->breakdown['prepay_ready'][0]['pocket_id'])->toBe($fund->id);

    $next = $this->user->periods()->whereDate('starts_on', '2026-11-01')->firstOrFail();
    $leftoverBefore = resolve(PlanService::class)->summaryFor($next)->leftover;

    resolve(RecordPrepayment::class)->handle($this->user, $loan, 500_000, $fund->refresh());

    expect($loan->refresh()->installment)->toBe(77_856)
        ->and($loan->principal_balance)->toBe(3_500_000)
        ->and($fund->refresh()->balance)->toBe(0)
        ->and(BudgetLine::query()->where('loan_id', $loan->id)->value('amount'))->toBe(77_856 + 2_549)
        ->and(resolve(PlanService::class)->summaryFor($next->refresh())->leftover)->toBe($leftoverBefore + 11_122);
});

it('walks through the three-step closing wizard', function (): void {
    spend($this->user, $this->period, $this->fuel, 60_000);

    Livewire::test('pages::close', ['period' => $this->period->id])
        ->assertSet('step', 1)
        ->assertSee('Fuel')
        ->set('incomeActual', '510000')
        ->call('next')
        ->assertSet('step', 2)
        ->assertSee(money(250_000))
        ->call('next')
        ->assertSet('step', 3)
        ->call('close')
        ->assertSet('step', 4)
        ->assertSee('Period closed');

    expect($this->period->refresh()->isOpen())->toBeFalse()
        ->and($this->period->income_actual)->toBe(510_000);

    $this->get(route('month', ['periodus' => $this->period->id]))->assertSee('data-test="close-summary"', false);
});

it('offers the prepayment right after closing', function (): void {
    $loan = $this->user->loans()->create(['name' => 'Car', 'principal_balance' => 1_000_000, 'installment' => 30_000, 'prepay_mode' => PrepayMode::ReduceInstallment]);
    $fund = Pocket::factory()->for($this->user)->create(['name' => 'Car fund', 'balance' => 600_000, 'prepay_step' => 500_000, 'loan_id' => $loan->id]);

    Livewire::test('pages::close', ['period' => $this->period->id])
        ->call('next')->call('next')->call('close')
        ->assertSee('Prepayment ready')
        ->call('prepay', $fund->id);

    expect($loan->refresh()->principal_balance)->toBe(500_000)
        ->and($loan->installment)->toBe(15_000)
        ->and($fund->refresh()->balance)->toBe(100_000);
});

it('cannot open another user\'s period', function (): void {
    $foreign = Period::factory()->create();

    $this->get(route('close', $foreign))->assertNotFound();
});
