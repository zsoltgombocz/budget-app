<?php

use App\Actions\Budget\RecordPrepayment;
use App\Actions\Month\PlanLoan;
use App\Enums\LineType;
use App\Enums\PeriodMode;
use App\Enums\PrepayMode;
use App\Models\BudgetLine;
use App\Models\BudgetSetting;
use App\Models\Category;
use App\Models\Loan;
use App\Models\User;
use App\Services\MonthService;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 19:00'));
    $this->user = User::factory()->plus()->create();
    BudgetSetting::factory()->for($this->user)->create(['period_mode' => PeriodMode::Calendar, 'income' => 450_000, 'locale' => 'hu', 'onboarded_at' => now()]);
    $category = Category::factory()->for($this->user)->create(['name' => 'Autóhitel', 'type' => LineType::Loan]);
    $this->line = BudgetLine::factory()->for($this->user)->for($category)->create(['amount' => 72_600, 'due_day' => 5]);
    $this->period = resolve(PeriodService::class)->forDate($this->user, CarbonImmutable::parse('2026-10-15'));
    app()->setLocale('hu');
    $this->actingAs($this->user);
});

function carLoan(object $test): Loan
{
    return resolve(PlanLoan::class)->details($test->user, $test->line, ['principal' => 3_200_000, 'thm' => 9.9, 'remaining_months' => 55, 'prepay_mode' => PrepayMode::ReduceTerm]);
}

it('adds the loan behind an installment line, with the line\'s amount as the installment', function (): void {
    $loan = carLoan($this);

    expect($loan->installment)->toBe(72_600)
        ->and($loan->name)->toBe('Autóhitel')
        ->and($this->line->fresh()->loan_id)->toBe($loan->id);

    resolve(PlanLoan::class)->details($this->user, $this->line->fresh(), ['principal' => 3_100_000, 'thm' => 9.9, 'remaining_months' => 54, 'prepay_mode' => PrepayMode::ReduceTerm]);

    expect($this->user->loans()->count())->toBe(1)
        ->and($loan->fresh()->principal_balance)->toBe(3_100_000);
});

it('rejects missing loan details', function (): void {
    resolve(PlanLoan::class)->details($this->user, $this->line, ['principal' => 0, 'thm' => 120.0, 'remaining_months' => 0, 'prepay_mode' => PrepayMode::ReduceTerm]);
})->throws(ValidationException::class);

it('sets aside a monthly prepayment saving as a savings line, and stops it from this month on', function (): void {
    $loan = carLoan($this);
    $pocket = resolve(PlanLoan::class)->prepay($this->user, $loan, 30_000, 500_000, PrepayMode::ReduceInstallment);

    $saving = $this->user->budgetLines()->with('category')->where('pocket_id', $pocket->id)->sole();
    expect($pocket->loan_id)->toBe($loan->id)
        ->and($pocket->prepay_step)->toBe(500_000)
        ->and($saving->amount)->toBe(30_000)
        ->and($saving->category->type)->toBe(LineType::Sinking)
        ->and($saving->manual_pay)->toBeTrue()
        ->and($loan->fresh()->prepay_mode)->toBe(PrepayMode::ReduceInstallment);

    $view = fn () => resolve(MonthService::class)->view($this->user, $this->period->refresh(), CarbonImmutable::parse('2026-10-15'));
    expect($view()->fixedTotal)->toBe(102_600);

    resolve(PlanLoan::class)->prepay($this->user, $loan, 40_000, 500_000, PrepayMode::ReduceTerm);
    expect($this->user->budgetLines()->where('pocket_id', $pocket->id)->whereNull('active_to')->sum('amount'))->toBe(40_000);

    resolve(PlanLoan::class)->prepay($this->user, $loan, 0, 500_000, PrepayMode::ReduceTerm);
    expect($view()->fixedTotal)->toBe(72_600)
        ->and($this->user->pockets()->goals()->count())->toBe(0);
});

it('undoes a prepayment: the loan as before, the money back in its pocket', function (): void {
    $loan = carLoan($this);
    $pocket = resolve(PlanLoan::class)->prepay($this->user, $loan, 30_000, 500_000, PrepayMode::ReduceTerm);
    $pocket->update(['balance' => 500_000]);

    $event = resolve(RecordPrepayment::class)->handle($this->user, $loan, 500_000, $pocket);
    expect($loan->fresh()->principal_balance)->toBe(2_700_000)
        ->and($pocket->fresh()->balance)->toBe(0);

    resolve(RecordPrepayment::class)->undo($this->user, $event);

    expect($loan->fresh()->principal_balance)->toBe(3_200_000)
        ->and($loan->fresh()->remaining_months)->toBe(55)
        ->and($pocket->fresh()->balance)->toBe(500_000)
        ->and($loan->events()->count())->toBe(0)
        ->and($this->user->pocketMovements()->count())->toBe(0);
});

it('asks for the loan\'s details first, then plans with the slider', function (): void {
    Livewire::test('pages::loan', ['line' => $this->line])
        ->assertSee('Add meg a hitel adatait')
        ->call('saveDetails', ['principal' => '3200000', 'thm' => '9,9', 'months' => '55', 'mode' => 'reduce_term'])
        ->assertReturned(['ok' => true, 'errors' => []])
        ->assertSee('Kifut: 2031. május')
        ->assertSet('monthly', 30_000)
        ->assertSee('2029. december')
        ->assertSee('1 év 5 hónappal hamarabb')
        ->set('monthly', 0)
        ->assertDontSee('hónappal hamarabb')
        ->set('monthly', 30_000)
        ->call('savePlan')
        ->assertDispatched('app-toast');

    expect($this->user->budgetLines()->whereNotNull('pocket_id')->sole()->amount)->toBe(30_000);
});

it('shows the details errors on the sheet', function (): void {
    Livewire::test('pages::loan', ['line' => $this->line])
        ->call('saveDetails', ['principal' => '', 'thm' => 'sok', 'months' => '0', 'mode' => 'reduce_term'])
        ->assertReturned(fn (array $result): bool => $result['ok'] === false && isset($result['errors']['principal'], $result['errors']['thm'], $result['errors']['remaining_months']));
});

it('notes a prepayment from the saving and undoes it from the toast', function (): void {
    $loan = carLoan($this);
    $pocket = resolve(PlanLoan::class)->prepay($this->user, $loan, 30_000, 500_000, PrepayMode::ReduceTerm);
    $pocket->update(['balance' => 500_000]);

    $page = Livewire::test('pages::loan', ['line' => $this->line])
        ->assertSee('Megvan az '.money(500_000).'!')
        ->call('recordPrepayment', '500000')
        ->assertReturned(['ok' => true, 'error' => null])
        ->assertDispatched('app-toast')
        ->assertSee('1. előtörlesztés');

    expect($loan->fresh()->principal_balance)->toBe(2_700_000)
        ->and($pocket->fresh()->balance)->toBe(0);

    $page->call('undoPrepayment', $loan->events()->sole()->id);

    expect($loan->fresh()->principal_balance)->toBe(3_200_000)
        ->and($pocket->fresh()->balance)->toBe(500_000);
});

it('does not note more than what is left of the loan', function (): void {
    carLoan($this);

    Livewire::test('pages::loan', ['line' => $this->line])
        ->call('recordPrepayment', '4000000')
        ->assertReturned(fn (array $result): bool => $result['ok'] === false);
});

it('only opens for the user\'s own loan installment lines', function (): void {
    $bills = BudgetLine::factory()->for($this->user)->for(Category::factory()->for($this->user)->create(['type' => LineType::Fixed]))->create();
    $this->get(route('loan', $bills))->assertNotFound();

    $other = User::factory()->create();
    $theirs = BudgetLine::factory()->for($other)->for(Category::factory()->for($other)->create(['type' => LineType::Loan]))->create();
    $this->get(route('loan', $theirs))->assertNotFound();

    $this->get(route('loan', $this->line))->assertOk()->assertSee('Mikor leszek hitelmentes?');
});

it('shows the prepayment being saved up on Today and Month, and links the planner from the loan line', function (): void {
    $loan = carLoan($this);
    $pocket = resolve(PlanLoan::class)->prepay($this->user, $loan, 30_000, 500_000, PrepayMode::ReduceTerm);
    $pocket->update(['balance' => 340_000]);

    Livewire::test('pages::today')->assertSee('Következő előtörlesztés')->assertSee(money_number(340_000));
    Livewire::test('pages::month')->assertSee('Következő előtörlesztés')->assertSee(route('loan', $this->line));
});

it('shows what is in Plusz instead of the planner on Alap', function (): void {
    $this->user->forceFill(['plan' => null])->save();

    Livewire::test('pages::loan', ['line' => $this->line])
        ->assertSee('Mi van a Pluszban?')
        ->assertSee('Korlátlan figyelt kategória')
        ->assertDontSee('Add meg a hitel adatait');
});
