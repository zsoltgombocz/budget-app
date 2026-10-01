<?php

use App\Enums\CalcMode;
use App\Models\BudgetLine;
use App\Models\Category;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = onboardedUser();
    $this->actingAs($this->user);
});

it('shows the plan grouped by type with the summary', function (): void {
    $this->get(route('plan'))
        ->assertOk()
        ->assertSee('Rent')
        ->assertSee('Fuel')
        ->assertSee(money(150_000));
});

it('updates the summary immediately when an amount changes', function (): void {
    $fuel = BudgetLine::query()->whereRelation('category', 'name', 'Fuel')->firstOrFail();

    $component = Livewire::test('pages::plan')
        ->set("amounts.{$fuel->id}", '80000');

    expect($fuel->refresh()->amount)->toBe(80_000)
        ->and($component->instance()->summary->leftover)->toBe(130_000);
});

it('updates the income', function (): void {
    $component = Livewire::test('pages::plan')->set('income', '600 000');

    expect($this->user->settings()->refresh()->income)->toBe(600_000)
        ->and($component->instance()->summary->leftover)->toBe(250_000);
});

it('adds a variable line with average and maximum', function (): void {
    Livewire::test('pages::plan')
        ->call('create', 'variable')
        ->set('form.name', 'Coffee')
        ->set('form.amount', '10000')
        ->set('form.amountAvg', '12000')
        ->set('form.amountMax', '20000')
        ->set('form.calcMode', 'max')
        ->call('save')
        ->assertHasNoErrors();

    $line = BudgetLine::query()->whereRelation('category', 'name', 'Coffee')->sole();

    expect($line->calc_mode)->toBe(CalcMode::Max)
        ->and($line->amount_max)->toBe(20_000)
        ->and($line->category->is_quick_entry)->toBeTrue();
});

it('switches between average and maximum inline', function (): void {
    $fuel = BudgetLine::query()->whereRelation('category', 'name', 'Fuel')->firstOrFail();
    $fuel->update(['amount_avg' => 65_000, 'amount_max' => 80_000, 'calc_mode' => CalcMode::Avg]);

    $component = Livewire::test('pages::plan');
    expect($component->instance()->summary->leftover)->toBe(145_000);

    $component->call('setCalcMode', $fuel->id, 'max');
    expect($component->instance()->summary->leftover)->toBe(130_000);
});

it('edits a fixed line with due day and active window', function (): void {
    $rent = BudgetLine::query()->whereRelation('category', 'name', 'Rent')->firstOrFail();

    Livewire::test('pages::plan')
        ->call('edit', $rent->id)
        ->assertSet('form.name', 'Rent')
        ->set('form.dueDay', 5)
        ->set('form.activeTo', '2027-06-30')
        ->call('save')
        ->assertHasNoErrors();

    expect($rent->refresh()->due_day)->toBe(5)
        ->and($rent->active_to->toDateString())->toBe('2027-06-30');
});

it('validates amounts', function (): void {
    Livewire::test('pages::plan')
        ->call('create', 'fixed')
        ->set('form.name', 'Gym')
        ->set('form.amount', 'abc')
        ->call('save')
        ->assertHasErrors('form.amount');
});

it('removes a line and keeps its category for history', function (): void {
    $rent = BudgetLine::query()->whereRelation('category', 'name', 'Rent')->firstOrFail();

    Livewire::test('pages::plan')->call('edit', $rent->id)->call('delete');

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

    Livewire::test('pages::plan')->call('edit', $foreign->id)->assertNotFound();
});
