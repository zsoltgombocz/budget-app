<?php

use App\Enums\CloseDecision;
use App\Enums\LineType;
use App\Enums\PeriodMode;
use App\Enums\PeriodStatus;
use App\Models\BudgetLine;
use App\Models\BudgetSetting;
use App\Models\Category;
use App\Models\PeriodClose;
use App\Models\Pocket;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WatchedCategory;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00'));
    $this->user = User::factory()->create();
    BudgetSetting::factory()->for($this->user)->create(['period_mode' => PeriodMode::Calendar, 'income' => 450_000, 'locale' => 'hu', 'onboarded_at' => now()]);
    $rent = Category::factory()->for($this->user)->create(['name' => 'Lakbér', 'type' => LineType::Fixed]);
    $this->rent = BudgetLine::factory()->for($this->user)->for($rent)->create(['amount' => 150_000, 'due_day' => 10]);
    $bills = Category::factory()->for($this->user)->create(['name' => 'Rezsi', 'type' => LineType::Fixed]);
    $this->bills = BudgetLine::factory()->for($this->user)->for($bills)->create(['amount' => 38_000, 'due_day' => 20, 'manual_pay' => true]);
    $other = Category::factory()->for($this->user)->create(['name' => 'Többi', 'type' => LineType::Fixed]);
    BudgetLine::factory()->for($this->user)->for($other)->create(['amount' => 62_000]);
    $this->goal = Pocket::factory()->for($this->user)->create(['name' => 'Nyaralás', 'balance' => 100_000]);
    $savings = Category::factory()->for($this->user)->create(['name' => 'Félretétel · Nyaralás', 'type' => LineType::Sinking]);
    BudgetLine::factory()->for($this->user)->for($savings)->create(['amount' => 45_000, 'due_day' => 12, 'manual_pay' => true, 'pocket_id' => $this->goal->id]);
    $this->food = Category::factory()->for($this->user)->create(['name' => 'Étterem', 'type' => LineType::Variable]);
    $this->shop = Category::factory()->for($this->user)->create(['name' => 'Bolt', 'type' => LineType::Variable]);
    $this->period = resolve(PeriodService::class)->forDate($this->user, CarbonImmutable::parse('2026-10-15'));
    Transaction::factory()->for($this->user)->for($this->period)->create(['category_id' => $this->food->id, 'amount' => 24_000, 'occurred_on' => '2026-10-14']);
    Transaction::factory()->for($this->user)->for($this->period)->create(['category_id' => $this->shop->id, 'amount' => 46_000, 'occurred_on' => '2026-10-13']);
    app()->setLocale('hu');
    $this->actingAs($this->user);
});

it('sums the month up: what it still has, what went, the daily allowance', function (): void {
    Livewire::test('pages::month')
        ->assertSeeInOrder(['Még erre a hónapra', money_number(85_000), 'Jó tempóban vagy'])
        ->assertSee('Napi keret '.money(5_000));
});

it('builds the month from income, fixed expenses and savings', function (): void {
    Livewire::test('pages::month')
        ->assertSeeInOrder(['A hónapod', 'Bevétel', money(450_000), 'Fix kiadások', '−'.money(250_000), 'Félretétel', '−'.money(45_000), 'Költőpénz', money(155_000)])
        ->assertSee('Fix kiadások: 3-ból 1 levonva')
        ->assertSee('1-nek nincs napja');
});

it('lists where the money went, the biggest first, and filters the days by it', function (): void {
    Livewire::test('pages::month')
        ->assertSeeInOrder(['Hova ment?', 'Bolt', '66%', 'Étterem', '34%'])
        ->call('$set', 'categoryId', $this->food->id)
        ->assertSet('categoryId', $this->food->id);
});

it('watches a category, shows its ring and stops watching it', function (): void {
    $page = Livewire::test('pages::month')
        ->call('saveWatch', $this->food->id, '30000')
        ->assertReturned(['ok' => true, 'error' => null])
        ->assertSee('Étterem')
        ->assertSee('80%');

    expect(WatchedCategory::query()->sole()->amount)->toBe(30_000);

    $page->call('unwatch', $this->food->id);

    expect(WatchedCategory::query()->count())->toBe(0);
});

it('leaves a fixed line out of this month and back', function (): void {
    Livewire::test('pages::month')
        ->call('skipThisMonth', $this->bills->id)
        ->assertDispatched('app-toast')
        ->assertSee('ebben a hónapban nem lesz')
        ->call('undoSkip', $this->bills->id, false)
        ->assertDontSee('ebben a hónapban nem lesz');
});

it('counts another amount this month only', function (): void {
    Livewire::test('pages::month')
        ->call('amountThisMonth', $this->bills->id, '41200')
        ->assertReturned(['ok' => true, 'error' => null])
        ->assertSee(money_number(81_800));

    expect($this->bills->fresh()->amount)->toBe(38_000);
});

it('adds, changes and removes a fixed expense for every month', function (): void {
    $page = Livewire::test('pages::month')
        ->call('saveLine', null, ['name' => 'Telefon', 'type' => 'fixed', 'amount' => '9000', 'due_day' => '3', 'manual_pay' => false, 'goal_id' => ''])
        ->assertReturned(['ok' => true, 'errors' => []]);

    $line = BudgetLine::query()->whereRelation('category', 'name', 'Telefon')->sole();

    expect($line->amount)->toBe(9_000)->and($line->due_day)->toBe(3);

    $page->call('saveLine', $line->id, ['name' => 'Telefon és net', 'type' => 'fixed', 'amount' => '11000', 'due_day' => '', 'manual_pay' => true, 'goal_id' => ''])
        ->assertReturned(['ok' => true, 'errors' => []]);

    expect($line->fresh()->amount)->toBe(11_000)
        ->and($line->fresh()->due_day)->toBeNull()
        ->and($line->fresh()->manual_pay)->toBeTrue()
        ->and($line->fresh()->category->name)->toBe('Telefon és net');

    $page->call('deleteLine', $line->id);

    expect(BudgetLine::query()->find($line->id))->toBeNull();
});

it('refuses a fixed expense without a name or an amount', function (): void {
    Livewire::test('pages::month')
        ->call('saveLine', null, ['name' => '', 'type' => 'fixed', 'amount' => '', 'due_day' => '', 'manual_pay' => false, 'goal_id' => ''])
        ->assertReturned(fn (array $result): bool => $result['ok'] === false && isset($result['errors']['name'], $result['errors']['amount']));
});

it('sets a savings line aside from its sheet and adds it to the goal', function (): void {
    $savings = BudgetLine::query()->where('pocket_id', $this->goal->id)->sole();

    Livewire::test('pages::month')->call('markDone', $savings->id)->assertSee('félretéve');

    expect($this->goal->fresh()->balance)->toBe(145_000);
});

it('shows what a closed month left and what was decided', function (): void {
    $september = resolve(PeriodService::class)->forDate($this->user, CarbonImmutable::parse('2026-09-15'));
    $september->update(['status' => PeriodStatus::Closed]);
    PeriodClose::factory()->for($this->user)->for($september)->create(['leftover' => 18_400, 'decision' => CloseDecision::Carry, 'carried_over' => 18_400]);

    Livewire::test('pages::month', ['periodId' => $september->id])
        ->assertSee('Maradt')
        ->assertSee(money_number(18_400))
        ->assertSee('A következő hónapba vitted.');
});
