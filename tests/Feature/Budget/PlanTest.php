<?php

use App\Actions\Budget\SaveLoan;
use App\Actions\Budget\SavePlanLine;
use App\Enums\CalcMode;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\Loan;
use App\Models\Pocket;
use App\Models\Transaction;
use App\Services\PeriodService;
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
        ->assertSee(e(route('pockets', ['hitel' => 'uj', 'vissza' => 'terv'])), false);
});

it('opens the loan itself from its repayment line', function (): void {
    $loan = Loan::factory()->for($this->user)->create(['name' => 'Car loan', 'installment' => 40_000, 'insurance' => 0]);
    SaveLoan::addPlanLine($this->user, $loan);

    $this->get(route('plan'))->assertSee(e(route('pockets', ['hitel' => $loan->id, 'vissza' => 'terv'])), false);

    // Opened from the plan, the loan sheet returns there when it closes.
    $this->get(route('pockets', ['hitel' => $loan->id, 'vissza' => 'terv']))->assertOk()
        ->assertSee('openLoan: '.$loan->id, false)
        ->assertSee("returnTo: '".str_replace('/', '\\/', route('plan'))."'", false);
    $this->get(route('pockets', ['hitel' => $loan->id]))->assertOk()->assertSee('returnTo: null', false);
    $this->get(route('pockets', ['hitel' => 'uj']))->assertOk()->assertSee("openLoan: 'new'", false);
    $this->get(route('pockets', ['hitel' => 999_999]))->assertOk()->assertSee('openLoan: null', false);
});

it('shows each line with its coloured category tile, in edit mode next to the drag handle', function (): void {
    $html = $this->get(route('plan'))->assertOk()->getContent();
    expect(substr_count($html, 'data-test="line-icon"'))->toBe(3)
        ->and($html)->toMatch('/class="[^"]*bg-surface-2[^"]*" data-test="line-icon" style="width: 36px; height: 36px/');

    Livewire::test('pages::plan')->set('editing', true)
        ->assertSeeHtmlInOrder(['data-test="line-handle"', 'data-test="line-icon"', 'Rent']);
});

it('saves the average or maximum toggle without a toast and with a 44 px target', function (): void {
    line('Fuel')->update(['amount_avg' => 65_000, 'amount_max' => 80_000]);

    $html = $this->get(route('plan'))->getContent();
    expect($html)->toMatch('/wire:click="setCalcMode\(\d+, \'max\'\)"[^>]*class="focus-ring num h-11 /');

    Livewire::test('pages::plan')->call('setCalcMode', line('Fuel')->id, 'max')
        ->assertDispatched('budget-updated')
        ->assertNotDispatched('app-toast');

    expect(line('Fuel')->calc_mode)->toBe(CalcMode::Max);
});

it('keeps rows compact: a due day marker, and a subtotal only for groups with several lines', function (): void {
    line('Rent')->update(['due_day' => 12]);

    $html = $this->get(route('plan'))->assertOk()
        ->assertSeeInOrder(['data-test="due-day"', '12.'], false)
        ->assertDontSee('data-test="line-detail"', false)
        ->getContent();

    // One fixed line, two variable ones: only the variable group repeats nothing with a subtotal.
    expect(substr_count($html, 'data-test="subtotal"'))->toBe(1);
});

it('keeps the whole loan detail on its own line under the name', function (): void {
    $loan = Loan::factory()->for($this->user)->create(['principal_balance' => 3_200_000, 'remaining_months' => 44]);
    $category = Category::factory()->for($this->user)->create(['name' => 'Car loan', 'type' => 'loan']);
    BudgetLine::factory()->for($this->user)->for($category)->create(['amount' => 87_549, 'loan_id' => $loan->id]);

    $this->get(route('plan'))
        ->assertSeeInOrder(['data-test="line-detail"', __('principal :amount', ['amount' => money(3_200_000)]), '44 months left'], false);
});

it('names the pocket of a line only when the name does not already say it', function (): void {
    $holiday = Pocket::factory()->for($this->user)->create(['name' => 'Holiday']);
    $travel = Category::factory()->for($this->user)->create(['name' => 'Summer trip', 'type' => 'sinking']);
    BudgetLine::factory()->for($this->user)->for($travel)->create(['amount' => 20_000, 'pocket_id' => $holiday->id]);

    $this->get(route('plan'))->assertSee(__('into :pocket', ['pocket' => 'Holiday']));

    $travel->update(['name' => 'holiday']);

    $this->get(route('plan'))->assertDontSee(__('into :pocket', ['pocket' => 'Holiday']));
});

it('shows the leftover green only when something is left', function (int $income, string $class): void {
    $this->user->settings()->update(['income' => $income]);

    $html = $this->get(route('plan'))->getContent();
    preg_match('/data-test="plan-left">.*?<span class="([^"]*)">/s', $html, $match);

    expect(trim($match[1] ?? 'missing'))->toBe($class);
})->with([
    'money left' => [500_000, 'text-accent-strong'],
    'nothing left' => [350_000, ''],
    'over the income' => [300_000, 'text-danger'],
]);

it('makes edit mode a visible state with a Done button and drag handles', function (): void {
    Livewire::test('pages::plan')
        ->assertSee(__('Plan'))
        ->assertDontSee('data-test="line-handle"', false)
        ->assertDontSee('data-test="done-editing"', false)
        ->set('editing', true)
        ->assertSeeHtmlInOrder(['data-test="plan-header"', __('Edit'), __('Done')])
        ->assertSee('data-test="line-handle"', false)
        ->assertSee('data-test="add-fixed"', false)
        ->assertSee('data-test="done-editing"', false)
        ->set('editing', false)
        ->assertDontSee('data-test="line-handle"', false);
});

it('says after saving that the change reaches the open period from its first day', function (): void {
    $this->travelTo('2026-10-15 12:00:00');

    $component = Livewire::test('pages::plan')
        ->assertSeeHtmlInOrder(['data-test="scope-note"', 'The change also applies to the current period (from Oct 1).']);
    $data = $component->instance()->lineData(line('Fuel')->id);
    $data['amounts']['amount'] = '80000';

    $component->call('saveLine', $data)
        ->assertReturned(['ok' => true, 'errors' => []])
        ->assertDispatched('app-toast', title: __('Plan updated.'), subtitle: 'Also applies to the current period (from Oct 1).');

    Livewire::test('pages::plan')->call('saveIncome', '600000')
        ->assertDispatched('app-toast', subtitle: 'Also applies to the current period (from Oct 1).');

    Livewire::test('pages::plan')->call('deleteLine', line('Rent')->id)
        ->assertDispatched('app-toast', title: __('Item removed from the plan.'), subtitle: 'Also applies to the current period (from Oct 1).');
});

it('does not claim the open period for a line that only starts later', function (): void {
    $this->travelTo('2026-10-15 12:00:00');

    $component = Livewire::test('pages::plan');
    $data = $component->instance()->lineData(line('Fuel')->id);
    $data['activeFrom'] = '2026-11-01';

    $component->call('saveLine', $data)
        ->assertReturned(['ok' => true, 'errors' => []])
        ->assertDispatched('app-toast', title: __('Plan updated.'), subtitle: null);

    expect($component->instance()->lineData(line('Fuel')->id)['deleteNote'])->toBe(__('Recorded spending stays, with its category. Closed months do not change.'));
});

it('sums up the leftover rule in one sentence on the plan', function (): void {
    $settings = $this->user->settings();
    $account = $this->user->accounts()->create(['name' => 'Broker', 'type' => 'investment', 'currency' => 'HUF']);
    $rule = fn (): string => Livewire::test('pages::plan')->instance()->leftoverRule();

    expect($rule())->toBe('All of it stays on the account');

    $settings->update(['surplus_account_id' => $account->id, 'reserve_pct' => 50]);
    expect($rule())->toBe('All of it goes to Broker');

    $reserve = Pocket::factory()->for($this->user)->create(['is_reserve' => true, 'target_amount' => 300_000]);
    expect($rule())->toBe('50% to the reserve up to a target of '.money(300_000).', the rest goes to Broker');

    $settings->update(['surplus_account_id' => null, 'reserve_pct' => 100]);
    expect($rule())->toBe('100% to the reserve up to a target of '.money(300_000).', the rest stays on the account');

    $reserve->update(['target_amount' => null]);
    expect($rule())->toBe('100% to the reserve');

    $settings->update(['reserve_fixed' => 40_000]);
    expect($rule())->toBe('Up to '.money(40_000).' to the reserve, the rest stays on the account');

    $settings->update(['reserve_fixed' => null, 'reserve_pct' => 0]);
    expect($rule())->toBe('All of it stays on the account');

    $this->get(route('plan'))->assertOk()
        ->assertSeeInOrder(['data-test="month-end"', __('Reserve and leftover'), 'data-test="leftover-rule"', 'All of it stays on the account', __('What you choose here is saved right away.'), 'data-test="reserve-card"'], false);
});

it('shows the leftover rules inline on the plan, as on the onboarding, without a sheet', function (): void {
    Pocket::factory()->for($this->user)->create(['is_reserve' => true, 'balance' => 120_000]);

    $this->get(route('plan'))->assertOk()
        ->assertSeeInOrder([
            'data-test="reserve-card"', __('Balance: :amount', ['amount' => money(120_000)]), 'data-test="reserve-row"',
            __('If money is left at month end'), 'data-test="reserve-mode-pct"', 'data-test="reserve-pct-50"',
            __('The rest goes to:'), 'data-test="surplus-target"',
            'data-test="leftover-preview"', __('A month with your numbers'),
        ], false)
        ->assertDontSee('data-test="leftover-sheet"', false);
});

it('saves the inline leftover choices at once and silently, and updates the sentence', function (): void {
    Pocket::factory()->for($this->user)->create(['is_reserve' => true]);
    $account = $this->user->accounts()->create(['name' => 'Broker', 'type' => 'investment', 'currency' => 'HUF']);

    Livewire::test('pages::plan')
        ->call('setReservePct', 25)
        ->assertSee('25% to the reserve, the rest stays on the account')
        ->call('setSurplusTarget', 'account:'.$account->id)
        ->assertSee('25% to the reserve, the rest goes to Broker')
        ->call('setReserveMode', 'fixed')
        ->assertSee('All of it goes to Broker')
        ->assertNotDispatched('app-toast');

    expect($this->user->settings()->refresh()->surplus_account_id)->toBe($account->id);
});

it('asks before a type change recounts spending already recorded in the open period', function (): void {
    $this->travelTo('2026-10-15 12:00:00');
    $period = resolve(PeriodService::class)->current($this->user);
    Transaction::factory()->for($this->user)->for($period)->for(line('Fuel')->category)->create(['amount' => 12_000]);

    $component = Livewire::test('pages::plan');
    $data = $component->instance()->lineData(line('Fuel')->id);
    $data['type'] = 'fixed';

    $component->call('saveLine', $data)
        ->assertReturned(fn (array $result): bool => $result['ok'] === false
            && $result['errors'] === []
            && $result['confirm']['title'] === __('Change the kind of this item?')
            && str_contains((string) $result['confirm']['body'], money(12_000))
            && str_contains((string) $result['confirm']['body'], 'both Today and the closing count the planned amount instead of that spending')
            && str_contains((string) $result['confirm']['body'], 'The change also applies to the current period (from Oct 1).'))
        ->assertNotDispatched('app-toast');

    expect(line('Fuel')->category->type->value)->toBe('variable');

    $component->call('saveLine', [...$data, 'typeChangeConfirmed' => true])->assertReturned(['ok' => true, 'errors' => []]);

    expect(line('Fuel')->category->type->value)->toBe('fixed');

    // Back to variable: the same spending would now be counted instead of the plan.
    $data = $component->instance()->lineData(line('Fuel')->id);
    $data['type'] = 'variable';

    $component->call('saveLine', $data)
        ->assertReturned(fn (array $result): bool => $result['ok'] === false && str_contains((string) $result['confirm']['body'], 'closing counts that recorded spending instead of the planned amount'));
});

it('changes the type without a question when no recorded spending is recounted', function (): void {
    $period = resolve(PeriodService::class)->current($this->user);
    Transaction::factory()->for($this->user)->for($period)->for(line('Rent')->category)->create(['amount' => 200_000]);

    $component = Livewire::test('pages::plan');

    // No spending on Fuel at all.
    $data = $component->instance()->lineData(line('Fuel')->id);
    $data['type'] = 'fixed';
    $component->call('saveLine', $data)->assertReturned(['ok' => true, 'errors' => []]);

    // Rent has spending, but fixed and transfer both count with the planned amount.
    $data = $component->instance()->lineData(line('Rent')->id);
    $data['type'] = 'transfer';
    $component->call('saveLine', $data)->assertReturned(['ok' => true, 'errors' => []]);

    // Other edits of a line with spending never ask.
    $data = $component->instance()->lineData(line('Rent')->id);
    $data['amounts']['amount'] = '210000';
    $component->call('saveLine', $data)->assertReturned(['ok' => true, 'errors' => []]);

    expect(line('Fuel')->category->type->value)->toBe('fixed')
        ->and(line('Rent')->category->type->value)->toBe('transfer')
        ->and(line('Rent')->amount)->toBe(210_000);
});

it('offers deleting as a text button at the bottom of the sheet and says what it does', function (): void {
    $this->travelTo('2026-10-15 12:00:00');

    $component = Livewire::test('pages::plan')
        ->assertSeeHtmlInOrder(['data-test="line-sheet"', __('Active until'), 'x-on:click="removeLine()"', 'data-test="delete-line"', __('Delete item'), 'data-test="save-line"']);

    expect(substr_count($component->html(), 'removeLine()'))->toBe(2);

    expect($component->instance()->lineData(line('Rent')->id)['deleteNote'])
        ->toBe('Recorded spending stays, with its category. The item also leaves the plan of the current period (from Oct 1); closed months do not change.')
        ->and($component->instance()->lineData(null, 'fixed')['deleteNote'])->toBe('');
});

it('offers the icons with a name in words and marks the reorder handle and the mode toggles', function (): void {
    $html = $this->get(route('plan'))->assertOk()
        ->assertSee('aria-label="Shopping basket"', false)
        ->assertDontSee('aria-label="shopping_basket"', false)
        ->assertSee('data-test="icon-shopping_basket"', false)
        ->getContent();

    expect($html)->toMatch('/<button[^>]*:aria-pressed="line\.icon ===[^>]*min-h-11/');
});

it('puts no minus sign before a zero in the month preview', function (): void {
    Pocket::factory()->for($this->user)->create(['is_reserve' => true]);

    $this->get(route('plan'))->assertOk()
        ->assertSeeInOrder([__('Put aside in the reserve'), money(0)], false)
        ->assertDontSee('−'.money(0), false)
        ->assertSee('−'.money(350_000), false);
});
