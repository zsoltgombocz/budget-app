<?php

use App\Actions\Budget\MovePocketMoney;
use App\Actions\Budget\RecordPrepayment;
use App\Actions\Budget\RecordTransaction;
use App\Actions\Budget\SetLineStatus;
use App\Enums\LineStatus;
use App\Enums\PeriodStatus;
use App\Enums\PocketMovementType;
use App\Enums\PrepayMode;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\Period;
use App\Models\Pocket;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\SurplusTransferReminder;
use App\Services\PeriodCloser;
use App\Services\PeriodService;
use App\Services\PlanService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
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

it('puts a fixed amount of the leftover into the reserve when the rule is a fixed amount', function (): void {
    $this->user->settings()->update(['reserve_fixed' => 30_000, 'reserve_pct' => 100]);
    $this->reserve->update(['target_amount' => null]);

    $close = resolve(PeriodCloser::class)->close($this->user, $this->period);

    expect($close->to_reserve)->toBe(30_000);
});

it('leaves the reserve alone when the user chooses not to cover the deficit', function (): void {
    spend($this->user, $this->period, $this->fuel, 400_000);

    $close = resolve(PeriodCloser::class)->close($this->user, $this->period, coverDeficit: false);

    expect($close->leftover)->toBe(-100_000)
        ->and($close->from_reserve)->toBe(0)
        ->and($close->breakdown['allocation']['uncovered'])->toBe(100_000)
        ->and($this->reserve->refresh()->balance)->toBe(80_000);
});

it('asks on the closing screen whether to take the deficit from the reserve', function (): void {
    spend($this->user, $this->period, $this->fuel, 400_000);

    Livewire::test('pages::close', ['period' => $this->period->id])
        ->set('step', 2)
        ->assertSee(__('You spent more than came in. Do you take the gap from the reserve?'))
        ->set('coverDeficit', false)
        ->call('close');

    expect($this->reserve->refresh()->balance)->toBe(80_000);
});

it('moves planned pocket savings into their pockets', function (): void {
    $category = Category::factory()->for($this->user)->create(['name' => 'Holiday', 'type' => 'sinking']);
    BudgetLine::factory()->for($this->user)->for($category)->create(['amount' => 25_000, 'pocket_id' => $this->savings->id]);

    resolve(PeriodCloser::class)->close($this->user, $this->period);

    expect($this->savings->movements()->where('type', PocketMovementType::Deposit)->where('note', 'Monthly saving')->value('amount'))->toBe(25_000);
});

it('shows the reserve\'s new balance including its monthly deposit', function (): void {
    $this->reserve->update(['target_amount' => null]);
    $category = Category::factory()->for($this->user)->create(['name' => 'Reserve saving', 'type' => 'sinking']);
    BudgetLine::factory()->for($this->user)->for($category)->create(['amount' => 20_000, 'pocket_id' => $this->reserve->id]);

    $preview = resolve(PeriodCloser::class)->preview($this->user, $this->period, null, 200_000);

    expect($preview->reserveBalanceAfter)->toBe(80_000 + 20_000 + 200_000);

    Livewire::test('pages::close', ['period' => $this->period->id])
        ->call('goTo', 2)
        ->call('split', 200_000)
        ->assertSee('new balance '.money(300_000))
        ->call('goTo', 3)
        ->assertSee('new balance '.money(300_000))
        ->call('close');

    expect($this->reserve->refresh()->balance)->toBe(300_000);
});

it('describes where the rest goes by the chosen target', function (): void {
    $broker = $this->user->accounts()->create(['name' => 'Broker', 'type' => 'investment', 'currency' => 'HUF']);
    $component = Livewire::test('pages::close', ['period' => $this->period->id])->call('goTo', 2);

    $component->assertSee('the app puts it into this pocket')->assertDontSee('manual transfer');
    $component->call('chooseTarget', 'account:'.$broker->id)->assertSee('manual transfer');
    $component->call('chooseTarget', 'none')->assertSee('nothing moves')->assertDontSee('manual transfer');
    $component->call('chooseTarget', 'new-pocket')->assertSee('the app opens a new pocket for it');

    $component->call('chooseTarget', 'pocket:'.$this->savings->id)->call('goTo', 3)
        ->assertSee('the app puts it into this pocket')->assertDontSee('we remind you to transfer it');
    $component->call('chooseTarget', 'account:'.$broker->id)->assertSee('we remind you to transfer it');
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
        ->call('goTo', 2)
        ->assertSet('step', 2)
        ->assertSee(money_number(240_000))
        ->call('goTo', 3)
        ->call('setIncome', '510000')
        ->assertSet('incomeActual', 510_000)
        ->call('close')
        ->assertSet('step', 4)
        ->assertSee('October is closed');

    expect($this->period->refresh()->isOpen())->toBeFalse()
        ->and($this->period->income_actual)->toBe(510_000);

    $this->get(route('month', ['periodus' => $this->period->id]))->assertSee('data-test="close-summary"', false);
});

it('offers the prepayment right after closing', function (): void {
    $loan = $this->user->loans()->create(['name' => 'Car', 'principal_balance' => 1_000_000, 'installment' => 30_000, 'prepay_mode' => PrepayMode::ReduceInstallment]);
    $fund = Pocket::factory()->for($this->user)->create(['name' => 'Car fund', 'balance' => 600_000, 'prepay_step' => 500_000, 'loan_id' => $loan->id]);

    Livewire::test('pages::close', ['period' => $this->period->id])
        ->call('goTo', 2)->call('goTo', 3)->call('close')
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

it('splits a positive leftover by hand between the reserve and the surplus target', function (): void {
    Livewire::test('pages::close', ['period' => $this->period->id])
        ->call('goTo', 2)
        ->call('split', 5_000)
        ->call('goTo', 3)
        ->call('close');

    expect($this->reserve->refresh()->balance)->toBe(85_000)
        ->and($this->savings->refresh()->balance)->toBe(295_000);
});

it('caps a manual split at the leftover', function (): void {
    $preview = resolve(PeriodCloser::class)->preview($this->user, $this->period, null, 9_999_999);

    // 500 000 income − 200 000 rent, nothing spent yet.
    expect($preview->allocation->toReserve)->toBe(300_000)
        ->and($preview->allocation->toSurplus)->toBe(0);
});

it('counts pocket top-ups in the leftover', function (): void {
    resolve(MovePocketMoney::class)->handle($this->user, $this->reserve, -30_000, null, toBudget: true);

    expect(resolve(PeriodCloser::class)->preview($this->user, $this->period)->leftover())->toBe(330_000);
});

it('sends the leftover to a new savings pocket chosen at closing', function (): void {
    $this->user->settings()->update(['surplus_pocket_id' => null]);
    $this->savings->delete();

    Livewire::test('pages::close', ['period' => $this->period->id])
        ->call('goTo', 2)
        ->call('chooseTarget', 'new-pocket')
        ->set('saveTargetAsDefault', true)
        ->call('goTo', 3)
        ->call('close');

    $pocket = Pocket::query()->where('name', 'Savings')->sole();

    expect($pocket->balance)->toBe(280_000)
        ->and($this->user->settings()->refresh()->surplus_pocket_id)->toBe($pocket->id);
});

it('reminds to transfer the leftover to an investment account', function (): void {
    Notification::fake();
    $this->user->updatePushSubscription('https://push.example.com/abc', 'key', 'token');
    $broker = $this->user->accounts()->create(['name' => 'Broker', 'type' => 'investment', 'currency' => 'HUF']);

    $close = resolve(PeriodCloser::class)->close($this->user, $this->period, null, null, 'account:'.$broker->id);

    expect($close->surplus_account_id)->toBe($broker->id)
        ->and($close->awaitsTransfer())->toBeTrue();
    Notification::assertSentTo($this->user, SurplusTransferReminder::class);

    $this->get(route('dashboard'))->assertSee('data-test="pending-transfer"', false);
    Livewire::test('pages::today')->call('markTransferred', $close->id);

    expect($close->refresh()->awaitsTransfer())->toBeFalse();
});

it('ignores another user\'s account as a closing target', function (): void {
    $foreign = onboardedUser()->accounts()->create(['name' => 'X', 'type' => 'investment', 'currency' => 'HUF']);

    Livewire::test('pages::close', ['period' => $this->period->id])
        ->call('chooseTarget', 'account:'.$foreign->id)
        ->assertSet('surplusTarget', null);
});

it('splits the leftover from either box on the numpad', function (): void {
    $component = Livewire::test('pages::close', ['period' => $this->period->id])->call('goTo', 2);

    expect($component->call('setSplit', 'reserve', '50000')->get('toReserve'))->toBe(50_000)
        ->and($component->call('setSplit', 'rest', '100000')->get('toReserve'))->toBe(200_000)
        ->and($component->instance()->setSplit('reserve', '300001')['ok'])->toBeFalse()
        ->and($component->get('toReserve'))->toBe(200_000);

    expect($component->instance()->preview->allocation->toSurplus)->toBe(100_000);
});

it('offers to keep the leftover target only when it differs from the saved one', function (): void {
    Livewire::test('pages::close', ['period' => $this->period->id])
        ->call('goTo', 2)
        ->assertDontSee('data-test="save-target"', false)
        ->call('chooseTarget', 'none')
        ->assertSee('data-test="save-target"', false)
        ->call('chooseTarget', 'pocket:'.$this->savings->id)
        ->assertDontSee('data-test="save-target"', false);
});

it('starts the new month on the day of an early closing', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-27 18:00', 'Europe/Budapest'));
    spend($this->user, $this->period, $this->fuel, 10_000);

    resolve(PeriodCloser::class)->close($this->user, $this->period);

    $next = resolve(PeriodService::class)->current($this->user);

    expect($this->period->refresh()->ends_on->toDateString())->toBe('2026-10-27')
        ->and($this->period->status)->toBe(PeriodStatus::Closed)
        ->and($next->starts_on->toDateString())->toBe('2026-10-27')
        ->and($next->ends_on->toDateString())->toBe('2026-11-30')
        ->and($next->isOpen())->toBeTrue()
        ->and($next->nameDate()->month)->toBe(11)
        ->and($this->period->nameDate()->month)->toBe(10);

    $transaction = resolve(RecordTransaction::class)->handle($this->user, $this->groceries->id, 5_000);

    expect($transaction->period_id)->toBe($next->id);

    $this->get(route('month'))->assertOk()->assertSee('November')->assertSee('data-test="close-now"', false);
});

it('can only be closed in the last week of the period', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 18:00', 'Europe/Budapest'));

    expect(resolve(PeriodCloser::class)->canClose($this->user, $this->period))->toBeFalse()
        ->and(fn () => resolve(PeriodCloser::class)->close($this->user, $this->period))->toThrow(ValidationException::class);

    $this->get(route('month'))->assertSee('data-test="close-not-yet"', false)->assertDontSee('data-test="start-close"', false);
    $this->get(route('close', $this->period))->assertRedirect(route('month', ['periodus' => $this->period->id]));

    $this->travelTo(CarbonImmutable::parse('2026-10-25 08:00', 'Europe/Budapest'));

    expect(resolve(PeriodCloser::class)->canClose($this->user, $this->period))->toBeTrue();
    $this->get(route('month'))->assertSee('data-test="start-close"', false);
});

it('lists every pocket movement of the closing with its parts and the new balance', function (): void {
    $this->reserve->update(['target_amount' => 150_000]);
    $category = Category::factory()->for($this->user)->create(['name' => 'Reserve saving', 'type' => 'sinking']);
    BudgetLine::factory()->for($this->user)->for($category)->create(['amount' => 20_000, 'pocket_id' => $this->reserve->id]);

    $preview = resolve(PeriodCloser::class)->preview($this->user, $this->period);
    $moves = collect($preview->pocketMoves)->keyBy('name');

    // 500 000 − 200 000 rent − 20 000 saving = 280 000 leftover; the reserve needs 50 000 more after its saving.
    expect($preview->reserveRoom)->toBe(50_000)
        ->and($preview->ruleToReserve)->toBe(50_000)
        ->and($moves['Reserve'])->toMatchArray(['monthly' => 20_000, 'leftover' => 50_000, 'cover' => 0, 'total' => 70_000, 'balance_after' => 150_000])
        ->and($moves['Savings'])->toMatchArray(['monthly' => 0, 'leftover' => 230_000, 'total' => 230_000, 'balance_after' => 230_000]);

    $close = resolve(PeriodCloser::class)->close($this->user, $this->period);

    expect($close->breakdown['pocket_moves'])->toBe($preview->pocketMoves);
});

it('shows a deficit taken from the reserve as a pocket movement', function (): void {
    spend($this->user, $this->period, $this->fuel, 350_000);

    $moves = resolve(PeriodCloser::class)->preview($this->user, $this->period)->pocketMoves;

    expect($moves)->toHaveCount(1)
        ->and($moves[0])->toMatchArray(['name' => 'Reserve', 'cover' => -50_000, 'total' => -50_000, 'balance_after' => 30_000]);
});

it('lists the new savings pocket the closing would open', function (): void {
    $moves = resolve(PeriodCloser::class)->preview($this->user, $this->period, surplusTarget: 'new-pocket')->pocketMoves;

    expect(collect($moves)->firstWhere('pocket_id', null))->toMatchArray(['name' => 'Savings', 'leftover' => 280_000, 'balance_after' => 280_000]);
});

it('uses one sign for plan vs actual: + left in the budget, − overspent', function (): void {
    spend($this->user, $this->period, $this->fuel, 70_000);
    spend($this->user, $this->period, $this->groceries, 50_000);

    Livewire::test('pages::close', ['period' => $this->period->id])
        ->assertSeeInOrder(['Fuel', money_number(60_000), money_number(70_000), '−'.money_number(10_000), 'overspent'])
        ->assertSeeInOrder(['Groceries', money_number(90_000), money_number(50_000), '+'.money_number(40_000)])
        ->assertSee('role="table"', false)
        ->assertSee(money(200_000).', the planned amount')
        ->assertSee(money(30_000).' under the budget')
        ->assertSee('aria-valuetext="Step 1 of 3"', false);
});

it('names a skipped fixed item as skipped on the closing', function (): void {
    $rent = BudgetLine::query()->whereRelation('category', 'name', 'Rent')->firstOrFail();
    resolve(SetLineStatus::class)->handle($this->user, $this->period, $rent->id, LineStatus::Skipped);

    Livewire::test('pages::close', ['period' => $this->period->id])
        ->assertSeeInOrder(['Rent', 'skipped', money_number(200_000), '0', '+'.money_number(200_000)]);
});

it('explains the suggested reserve amount and a split above the target', function (): void {
    $this->reserve->update(['target_amount' => 300_000]);

    $component = Livewire::test('pages::close', ['period' => $this->period->id])
        ->call('goTo', 2)
        ->assertSee('The app only keeps records: you make the real transfers in your bank.')
        ->assertSee('Your rule: 100% of the leftover goes to the reserve. It stops at the target ('.money(300_000).'): '.money(220_000).' is still missing.')
        ->assertDontSee('data-test="over-target-note"', false);

    $component->call('splitPreset', 100)
        ->assertSet('toReserve', 300_000)
        ->assertSee('data-test="over-target-note"', false)
        ->assertDontSee('data-test="reserve-rule-note"', false);
});

it('keeps a quick split as the leftover rule when asked', function (): void {
    $this->reserve->update(['target_amount' => null]);

    Livewire::test('pages::close', ['period' => $this->period->id])
        ->call('goTo', 2)
        ->call('splitPreset', 50)
        ->assertSet('toReserve', 150_000)
        ->assertSee('data-test="save-split"', false)
        ->set('saveSplitAsRule', true)
        ->call('goTo', 3)
        ->call('close');

    expect($this->user->settings()->refresh()->reserve_pct)->toBe(50)
        ->and($this->user->settings()->reserve_fixed)->toBeNull();
});

it('does not keep a split typed by hand as the rule', function (): void {
    Livewire::test('pages::close', ['period' => $this->period->id])
        ->call('goTo', 2)
        ->call('splitPreset', 50)
        ->set('saveSplitAsRule', true)
        ->call('setSplit', 'reserve', '1000')
        ->assertSet('splitPreset', null)
        ->assertDontSee('data-test="save-split"', false)
        ->call('close');

    expect($this->user->settings()->refresh()->reserve_pct)->toBe(100);
});

it('shows the pocket movements before and after closing and names where the rest goes', function (): void {
    $category = Category::factory()->for($this->user)->create(['name' => 'Car saving', 'type' => 'sinking']);
    $fund = Pocket::factory()->for($this->user)->create(['name' => 'Car fund', 'balance' => 450_000]);
    BudgetLine::factory()->for($this->user)->for($category)->create(['amount' => 50_000, 'pocket_id' => $fund->id]);

    Livewire::test('pages::close', ['period' => $this->period->id])
        ->call('goTo', 3)
        ->assertSee('Into pockets')
        ->assertSeeInOrder(['Car fund', money_number(50_000), 'monthly saving', 'new balance', money_number(500_000)])
        ->assertSeeInOrder(['Reserve', money_number(20_000), 'from the leftover', 'new balance', money_number(100_000)])
        ->assertSee('new balance '.money(500_000))
        ->call('close')
        ->assertNotDispatched('app-toast')
        ->assertSee('Into pockets')
        ->assertSee('new balance '.money(500_000))
        ->assertSeeInOrder(['Savings', money_number(230_000), 'The app adds it to this pocket'])
        ->assertDontSee('>Rest<', false)
        ->assertSee('To the new month');
});

it('tells on the income editor that the actual income only counts in this closing', function (): void {
    Livewire::test('pages::close', ['period' => $this->period->id])
        ->call('goTo', 3)
        ->assertSee('Only counts in the October closing, your plan does not change.');
});
