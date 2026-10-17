<?php

use App\Actions\Budget\DeletePocket;
use App\Actions\Budget\MovePocketMoney;
use App\Actions\Budget\PayFromPocket;
use App\Actions\Budget\RecordPrepayment;
use App\Actions\Budget\ResetBudget;
use App\Actions\Budget\SaveLoan;
use App\Enums\LineType;
use App\Enums\PrepayMode;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\Loan;
use App\Models\Pocket;
use App\Models\PocketMovement;
use App\Models\Transaction;
use App\Services\OverviewService;
use App\Services\PeriodCloser;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = onboardedUser();
    $this->actingAs($this->user);
    $this->page = fn () => Livewire::test('pages::pockets')->instance();
});

it('explains pockets when there are none', function (): void {
    $this->get(route('pockets'))->assertOk()->assertSee('Pockets collect money for a goal');
});

it('creates a pocket and moves money in and out', function (): void {
    $data = ($this->page)()->pocketData();
    $data['name'] = 'Holiday';
    $data['amounts']['target'] = '300000';

    expect(($this->page)()->savePocket($data)['ok'])->toBeTrue();

    $pocket = Pocket::query()->where('name', 'Holiday')->sole();
    ($this->page)()->movePocketMoney($pocket->id, 1, '50000', null, resolve(MovePocketMoney::class));
    ($this->page)()->movePocketMoney($pocket->id, -1, '20000', null, resolve(MovePocketMoney::class));

    expect($pocket->refresh()->balance)->toBe(30_000)
        ->and($pocket->target_amount)->toBe(300_000)
        ->and($pocket->movements()->count())->toBe(2);
});

it('rejects an empty pocket name', function (): void {
    $result = ($this->page)()->savePocket(($this->page)()->pocketData());

    expect($result['ok'])->toBeFalse()->and($result['errors'])->toHaveKey('name');
});

it('keeps a single reserve pocket', function (): void {
    $old = Pocket::factory()->for($this->user)->create(['is_reserve' => true]);
    $data = ($this->page)()->pocketData();
    $data['name'] = 'New reserve';
    $data['isReserve'] = true;

    ($this->page)()->savePocket($data);

    expect($old->refresh()->is_reserve)->toBeFalse()
        ->and(Pocket::query()->where('is_reserve', true)->value('name'))->toBe('New reserve');
});

it('saves a loan with a decimal APR and syncs its plan line', function (): void {
    $loan = Loan::factory()->for($this->user)->create(['installment' => 80_000, 'insurance' => 2_000]);
    $category = Category::factory()->for($this->user)->create(['type' => 'loan']);
    $line = BudgetLine::factory()->for($this->user)->for($category)->create(['amount' => 82_000, 'loan_id' => $loan->id]);

    $data = ($this->page)()->loanData($loan->id);
    $data['amounts']['installment'] = '75000';
    $data['amounts']['thm'] = '11,5';

    expect(($this->page)()->saveLoan($data, resolve(SaveLoan::class))['ok'])->toBeTrue()
        ->and($line->refresh()->amount)->toBe(77_000)
        ->and($loan->refresh()->thm)->toBe(11.5);
});

it('removes the monthly saving from the plan when its pocket is deleted', function (): void {
    $pocket = Pocket::factory()->for($this->user)->create(['name' => 'Reserve', 'is_reserve' => true]);
    $saving = Category::factory()->for($this->user)->create(['name' => 'Reserve', 'type' => 'sinking']);
    $line = BudgetLine::factory()->for($this->user)->for($saving)->create(['amount' => 25_000, 'pocket_id' => $pocket->id]);
    $transferCategory = Category::factory()->for($this->user)->create(['name' => 'Shared contribution', 'type' => 'transfer']);
    $transfer = BudgetLine::factory()->for($this->user)->for($transferCategory)->create(['amount' => 50_000, 'pocket_id' => $pocket->id]);

    ($this->page)()->deletePocket($pocket->id, resolve(DeletePocket::class));

    expect(Pocket::query()->find($pocket->id))->toBeNull()
        ->and(BudgetLine::query()->find($line->id))->toBeNull()
        ->and($saving->refresh()->trashed())->toBeTrue()
        ->and($transfer->refresh()->pocket_id)->toBeNull();
});

it('archives a deleted pocket without changing past periods', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-10 10:00', 'Europe/Budapest'));
    $fuel = Category::query()->where('name', 'Fuel')->firstOrFail();
    $pocket = Pocket::factory()->for($this->user)->create(['name' => 'Holiday', 'balance' => 100_000]);
    $this->user->settings()->update(['surplus_pocket_id' => $pocket->id]);

    resolve(MovePocketMoney::class)->handle($this->user, $pocket, -30_000, null, toBudget: true);
    $paid = resolve(PayFromPocket::class)->handle($this->user, $pocket, $fuel->id, 40_000, 'Car service');
    Transaction::factory()->for($this->user)->for(resolve(PeriodService::class)->current($this->user))->for($fuel)->create(['amount' => 25_000]);

    $this->travelTo(CarbonImmutable::parse('2026-09-30 18:00', 'Europe/Budapest'));
    $september = resolve(PeriodService::class)->current($this->user);
    resolve(PeriodCloser::class)->close($this->user, $september);

    $figures = function () use ($september): array {
        $september = $september->refresh();
        $forecast = resolve(OverviewService::class)->forPeriod($this->user, $september, $september->ends_on)->forecast;

        return [
            'top_ups' => resolve(PeriodService::class)->topUps($september),
            'spent' => $forecast->variableSpent,
            'leftover' => $forecast->expectedLeftover,
            'close' => $september->close?->only(['planned_total', 'actual_total', 'leftover', 'to_reserve', 'to_invest']),
        ];
    };
    $before = $figures();
    $movements = $this->user->pocketMovements()->count();

    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Europe/Budapest'));
    ($this->page)()->deletePocket($pocket->id, resolve(DeletePocket::class));

    expect($figures())->toBe($before)
        ->and($before['top_ups'])->toBe(30_000)
        ->and($before['spent'])->toBe(25_000)
        ->and($this->user->pocketMovements()->count())->toBe($movements)
        ->and($paid->refresh()->pocket_id)->toBe($pocket->id)
        ->and($paid->pocket->name)->toBe('Holiday')
        ->and(Pocket::withTrashed()->find($pocket->id)?->trashed())->toBeTrue()
        ->and(($this->page)()->pockets->pluck('id')->all())->not->toContain($pocket->id)
        ->and($this->user->settings()->refresh()->surplus_pocket_id)->toBeNull();

    Livewire::test('pages::month')->call('showPeriod', $september->id)->assertSee('paid from Holiday');

    expect(fn () => ($this->page)()->movePocketMoney($pocket->id, 1, '1000', null, resolve(MovePocketMoney::class)))
        ->toThrow(ModelNotFoundException::class);
});

it('removes archived pockets for good when the budget is reset', function (): void {
    $pocket = Pocket::factory()->for($this->user)->create();
    PocketMovement::factory()->for($this->user)->for($pocket)->create();
    ($this->page)()->deletePocket($pocket->id, resolve(DeletePocket::class));

    resolve(ResetBudget::class)->handle($this->user);

    expect(Pocket::withTrashed()->count())->toBe(0)
        ->and(PocketMovement::query()->count())->toBe(0);
});

it('puts a new loan into the plan as a repayment line straight away', function (): void {
    $data = ($this->page)()->loanData(null);
    $data['name'] = 'Home loan';
    $data['amounts']['principal'] = '10000000';
    $data['amounts']['installment'] = '85000';
    $data['amounts']['insurance'] = '2549';

    expect(($this->page)()->saveLoan($data, resolve(SaveLoan::class))['ok'])->toBeTrue();

    $loan = Loan::query()->where('name', 'Home loan')->sole();
    $line = BudgetLine::query()->where('loan_id', $loan->id)->sole();

    expect($line->amount)->toBe(87_549)
        ->and($line->category->type)->toBe(LineType::Loan)
        ->and($line->category->name)->toBe('Home loan');

    // Saving it again keeps one line and follows the new name.
    $data = ($this->page)()->loanData($loan->id);
    $data['name'] = 'Mortgage';
    ($this->page)()->saveLoan($data, resolve(SaveLoan::class));

    expect(BudgetLine::query()->where('loan_id', $loan->id)->count())->toBe(1)
        ->and($line->category->refresh()->name)->toBe('Mortgage');
});

it('keeps the installment due day on the repayment line', function (): void {
    $data = ($this->page)()->loanData(null);
    $data['name'] = 'Car loan';
    $data['amounts']['principal'] = '3000000';
    $data['amounts']['installment'] = '60000';
    $data['amounts']['dueDay'] = '15';

    expect(($this->page)()->saveLoan($data, resolve(SaveLoan::class))['ok'])->toBeTrue();

    $loan = Loan::query()->where('name', 'Car loan')->sole();
    expect(BudgetLine::query()->where('loan_id', $loan->id)->value('due_day'))->toBe(15)
        ->and(($this->page)()->loanData($loan->id)['amounts']['dueDay'])->toBe('15');

    $data = ($this->page)()->loanData($loan->id);
    $data['amounts']['dueDay'] = '32';
    expect(($this->page)()->saveLoan($data, resolve(SaveLoan::class))['errors'])->toHaveKey('dueDay');
});

it('gives loans that had no plan line their repayment line on migrate', function (): void {
    $loan = Loan::factory()->for($this->user)->create(['installment' => 60_000, 'insurance' => 0]);
    auth()->logout();

    (require database_path('migrations/2026_10_02_104027_add_plan_lines_for_loans_without_one.php'))->up();

    expect(BudgetLine::query()->withoutGlobalScopes()->where('loan_id', $loan->id)->value('amount'))->toBe(60_000);
});

it('records a prepayment from the linked pocket', function (): void {
    $loan = Loan::factory()->for($this->user)->create([
        'principal_balance' => 2_000_000, 'installment' => 50_000, 'insurance' => 0,
        'thm' => null, 'remaining_months' => null, 'prepay_mode' => PrepayMode::ReduceInstallment,
    ]);
    $pocket = Pocket::factory()->for($this->user)->create(['balance' => 500_000, 'prepay_step' => 500_000, 'loan_id' => $loan->id]);

    $data = ($this->page)()->prepayData($loan->id);
    expect($data['pocketId'])->toBe($pocket->id)->and($data['amounts']['prepay'])->toBe('500000');

    $result = ($this->page)()->prepay($loan->id, '500000', $pocket->id, resolve(RecordPrepayment::class));

    expect($result['ok'])->toBeTrue()
        ->and($loan->refresh()->principal_balance)->toBe(1_500_000)
        ->and($loan->installment)->toBe(37_500)
        ->and($pocket->refresh()->balance)->toBe(0);
});

it('refuses a prepayment larger than the pocket', function (): void {
    $loan = Loan::factory()->for($this->user)->create();
    $pocket = Pocket::factory()->for($this->user)->create(['balance' => 1_000]);

    $result = ($this->page)()->prepay($loan->id, '5000', $pocket->id, resolve(RecordPrepayment::class));

    expect($result['ok'])->toBeFalse()->and($result['errors'])->toHaveKey('prepay');
});

it('previews the installment after the next prepayment', function (): void {
    $loan = Loan::factory()->for($this->user)->create(['principal_balance' => 4_000_000, 'installment' => 88_978, 'insurance' => 2_549, 'thm' => 12.0, 'remaining_months' => 60]);
    $pocket = Pocket::factory()->for($this->user)->create(['prepay_step' => 500_000, 'loan_id' => $loan->id]);

    expect(($this->page)()->nextInstallment($loan, $pocket))->toBe(77_856 + 2_549);

    $this->get(route('pockets'))->assertSee(money(77_856 + 2_549));
});

it('cannot touch another user\'s pocket', function (): void {
    $foreign = Pocket::factory()->create();

    Livewire::test('pages::pockets')->call('pocketData', $foreign->id)->assertNotFound();
});

it('lists archived pockets and restores one with its balance', function (): void {
    $pocket = Pocket::factory()->for($this->user)->create(['name' => 'Holiday', 'balance' => 120_000]);

    Livewire::test('pages::pockets')
        ->call('deletePocket', $pocket->id)
        ->assertDispatched('app-toast')
        ->assertSee('data-test="archived-pockets"', false)
        ->assertSee('Holiday')
        ->call('restorePocket', $pocket->id)
        ->assertDontSee('data-test="archived-pockets"', false);

    expect(Pocket::query()->find($pocket->id)?->balance)->toBe(120_000);
});

it('puts deposit and withdraw buttons on every pocket card', function (): void {
    $holiday = Pocket::factory()->for($this->user)->create(['name' => 'Holiday', 'balance' => 120_000]);
    $empty = Pocket::factory()->for($this->user)->create(['name' => 'Bike', 'balance' => 0]);

    $html = (string) $this->get(route('pockets'))->assertOk()->getContent();

    expect($html)
        ->toContain("openPocket({$holiday->id}, 'deposit')")
        ->toContain("openPocket({$holiday->id}, 'budget')")
        ->toContain("openPocket({$empty->id}, 'deposit')")
        ->toMatch('/<button(?=[^>]*data-test="pocket-withdraw-'.$empty->id.'")(?=[^>]*\sdisabled="disabled")/')
        ->not->toMatch('/<button(?=[^>]*data-test="pocket-withdraw-'.$holiday->id.'")(?=[^>]*\sdisabled="disabled")/')
        ->not->toContain('data-test="pockets-empty"');
});

it('names the three money moves and says what each does', function (): void {
    Pocket::factory()->for($this->user)->create();

    $this->get(route('pockets'))
        ->assertSeeInOrder([
            'Money into the pocket from this month\'s money: the leftover goes down by the same amount.',
            'Withdraw to the monthly budget', 'The money leaves the pocket and counts as extra income in this period.',
            'Pay a spending from the pocket', 'The spending is paid by the pocket: it does not use up the monthly budget.',
        ])
        ->assertDontSee('To the budget');
});

it('keeps the primary buttons off until an amount is typed', function (): void {
    Pocket::factory()->for($this->user)->create();

    $this->get(route('pockets'))
        ->assertSee(':disabled="saving || ! positive(\'move\')"', false)
        ->assertSee(':disabled="saving || ! positive(\'prepay\')"', false);
});

it('gives loans their own section with their own add button', function (): void {
    Pocket::factory()->for($this->user)->create(['name' => 'Holiday']);

    $this->get(route('pockets'))
        ->assertSeeInOrder(['data-test="pockets-section"', 'Holiday', 'data-test="loans-section"', 'Loans', 'data-test="add-loan"', 'data-test="loans-empty"'], false);

    $loan = Loan::factory()->for($this->user)->create(['name' => 'Car loan']);

    $this->get(route('pockets'))
        ->assertSeeInOrder(['data-test="loans-section"', 'data-test="add-loan"', 'data-test="loan-'.$loan->id.'"', 'Car loan'], false)
        ->assertDontSee('data-test="loans-empty"', false);
});

it('labels the header add button', function (): void {
    $this->get(route('pockets'))->assertSeeInOrder(['data-test="add"', 'Add', 'data-test="pockets-section"'], false);
});

it('says what the prepayment pocket saves for instead of a step chip', function (): void {
    $loan = Loan::factory()->for($this->user)->create(['name' => 'Car loan']);
    Pocket::factory()->for($this->user)->create(['name' => 'Prepayment fund', 'balance' => 450_000, 'prepay_step' => 500_000, 'loan_id' => $loan->id]);

    $this->get(route('pockets'))
        ->assertSee('Saving to prepay Car loan')
        ->assertSee(money_number(450_000))
        ->assertSee('/ '.money_number(500_000))
        ->assertDontSee('step:');
});

it('offers a new pocket from the empty state', function (): void {
    $this->get(route('pockets'))
        ->assertSeeInOrder(['data-test="pockets-empty"', 'No pockets yet', 'data-test="empty-new-pocket"', 'New pocket'], false);
});

it('deletes a pocket from the bottom of its settings, not from the sheet header', function (): void {
    Pocket::factory()->for($this->user)->create();

    $html = (string) $this->get(route('pockets'))->assertOk()->getContent();

    expect(substr_count($html, 'x-on:click="removePocket()"'))->toBe(1)
        ->and($html)->toMatch('/data-test="pocket-name".*data-test="delete-pocket"[^>]*>Delete pocket<.*data-test="sheet-save"/s');
});

it('has Hungarian copy for the money moves', function (): void {
    app()->setLocale('hu');

    expect(__('Withdraw to the monthly budget'))->toBe('Kivét a havi keretbe')
        ->and(__('Pay a spending from the pocket'))->toBe('Költés kifizetése a perselyből')
        ->and(__('The money leaves the pocket and counts as extra income in this period.'))->toBe('A pénz kikerül a perselyből, és ebben a periódusban plusz bevételnek számít.')
        ->and(__('Delete pocket'))->toBe('Persely törlése');
});

it('names the repayment line after its loan when the loan is saved', function (): void {
    $user = onboardedUser();
    $loan = Loan::factory()->for($user)->create(['name' => 'Személyi kölcsön']);
    $category = Category::factory()->for($user)->create(['name' => 'Hitel', 'type' => 'loan']);
    BudgetLine::factory()->for($user)->for($category)->create(['loan_id' => $loan->id]);

    resolve(SaveLoan::class)->handle($user, [...$loan->only(['name', 'lender', 'principal_balance', 'installment', 'insurance', 'thm', 'remaining_months']), 'prepay_mode' => $loan->prepay_mode], $loan);

    expect($category->refresh()->name)->toBe('Személyi kölcsön');
});
