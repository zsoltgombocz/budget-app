<?php

use App\Actions\Budget\SaveLoan;
use App\Actions\Budget\SavePlanLine;
use App\Enums\CalcMode;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\Loan;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = onboardedUser();
    $this->actingAs($this->user);
});

function line(string $name): BudgetLine
{
    return BudgetLine::query()->whereRelation('category', 'name', $name)->firstOrFail();
}

it('shows the plan grouped by type with the summary', function (): void {
    $this->get(route('plan'))
        ->assertOk()
        ->assertSee('Rent')
        ->assertSee('Fuel')
        ->assertSee(money_number(150_000));
});

it('loads a line into the editor in numpad format', function (): void {
    $data = Livewire::test('pages::plan')->instance()->lineData(line('Fuel')->id);

    expect($data['name'])->toBe('Fuel')
        ->and($data['type'])->toBe('variable')
        ->and($data['amounts']['amount'])->toBe('60000');
});

it('saves an edited amount in one call and updates the summary', function (): void {
    $component = Livewire::test('pages::plan');
    $data = $component->instance()->lineData(line('Fuel')->id);
    $data['amounts']['amount'] = '80000';

    $result = $component->instance()->saveLine($data, resolve(SavePlanLine::class));

    expect($result['ok'])->toBeTrue()
        ->and(line('Fuel')->amount)->toBe(80_000)
        ->and($component->instance()->summary->leftover)->toBe(130_000);
});

it('returns validation errors without losing the sheet', function (): void {
    $component = Livewire::test('pages::plan');
    $data = $component->instance()->lineData(null, 'fixed');
    $data['name'] = '';

    $result = $component->instance()->saveLine($data, resolve(SavePlanLine::class));

    expect($result['ok'])->toBeFalse()
        ->and($result['errors'])->toHaveKey('form.name');
});

it('adds a variable line with average and maximum', function (): void {
    $component = Livewire::test('pages::plan');
    $data = $component->instance()->lineData(null, 'variable');
    $data['name'] = 'Coffee';
    $data['calcMode'] = 'max';
    $data['amounts'] = ['amount' => '10000', 'amountAvg' => '12000', 'amountMax' => '20000', 'dueDay' => ''];

    expect($component->instance()->saveLine($data, resolve(SavePlanLine::class))['ok'])->toBeTrue();

    $line = line('Coffee');

    expect($line->calc_mode)->toBe(CalcMode::Max)
        ->and($line->amount_max)->toBe(20_000)
        ->and($line->category->is_quick_entry)->toBeTrue();
});

it('sets the due day and active window of a fixed line', function (): void {
    $component = Livewire::test('pages::plan');
    $data = $component->instance()->lineData(line('Rent')->id);
    $data['amounts']['dueDay'] = '5';
    $data['activeTo'] = '2027-06-30';

    $component->instance()->saveLine($data, resolve(SavePlanLine::class));

    expect(line('Rent')->due_day)->toBe(5)
        ->and(line('Rent')->active_to->toDateString())->toBe('2027-06-30');
});

it('accepts decimals for EUR plans', function (): void {
    $this->user->settings()->update(['currency' => 'EUR']);
    $component = Livewire::test('pages::plan');
    $data = $component->instance()->lineData(null, 'fixed');
    $data['name'] = 'Phone';
    $data['amounts']['amount'] = '24,99';

    $component->instance()->saveLine($data, resolve(SavePlanLine::class));

    expect(line('Phone')->amount)->toBe(2_499);
});

it('switches between average and maximum inline', function (): void {
    line('Fuel')->update(['amount_avg' => 65_000, 'amount_max' => 80_000, 'calc_mode' => CalcMode::Avg]);

    $component = Livewire::test('pages::plan');
    expect($component->instance()->summary->leftover)->toBe(145_000);

    $component->call('setCalcMode', line('Fuel')->id, 'max');
    expect($component->instance()->summary->leftover)->toBe(130_000);
});

it('updates the income from the numpad sheet', function (): void {
    $result = Livewire::test('pages::plan')->instance()->saveIncome('600 000');

    expect($result['ok'])->toBeTrue()
        ->and($this->user->settings()->refresh()->income)->toBe(600_000);
});

it('shows loan details on loan lines', function (): void {
    $loan = Loan::factory()->for($this->user)->create(['principal_balance' => 3_200_000, 'remaining_months' => 44]);
    $category = Category::factory()->for($this->user)->create(['name' => 'Car loan', 'type' => 'loan']);
    BudgetLine::factory()->for($this->user)->for($category)->create(['amount' => 87_549, 'loan_id' => $loan->id]);

    $this->get(route('plan'))->assertSee('Car loan')->assertSee(money(3_200_000));
});

it('removes a line and keeps its category for history', function (): void {
    $rent = line('Rent');

    Livewire::test('pages::plan')->call('deleteLine', $rent->id);

    expect(BudgetLine::query()->find($rent->id))->toBeNull()
        ->and(Category::withTrashed()->find($rent->category_id)->trashed())->toBeTrue();
});

it('reorders categories within a type', function (): void {
    $groceries = Category::query()->where('name', 'Groceries')->firstOrFail();

    Livewire::test('pages::plan')->call('reorder', $groceries->id, 0);

    expect(Category::query()->where('type', 'variable')->orderBy('sort')->pluck('name')->all())->toBe(['Groceries', 'Fuel']);
});

it('cannot edit another user\'s line', function (): void {
    $foreign = BudgetLine::factory()->create();

    Livewire::test('pages::plan')->call('lineData', $foreign->id)->assertNotFound();
});

it('offers a new loan from the repayment section even without loans', function (): void {
    $this->get(route('plan'))
        ->assertOk()
        ->assertSee(__('Loan repayments'))
        ->assertSee(route('pockets', ['hitel' => 'uj']), false);
});

it('opens the loan itself from its repayment line', function (): void {
    $loan = Loan::factory()->for($this->user)->create(['name' => 'Car loan', 'installment' => 40_000, 'insurance' => 0]);
    SaveLoan::addPlanLine($this->user, $loan);

    $this->get(route('plan'))->assertSee(route('pockets', ['hitel' => $loan->id]), false);

    $this->get(route('pockets', ['hitel' => $loan->id]))->assertOk()->assertSee('openLoan: '.$loan->id, false);
    $this->get(route('pockets', ['hitel' => 'uj']))->assertOk()->assertSee("openLoan: 'new'", false);
    $this->get(route('pockets', ['hitel' => 999_999]))->assertOk()->assertSee('openLoan: null', false);
});
