<?php

use App\Enums\LineType;
use App\Enums\PeriodMode;
use App\Models\BudgetLine;
use App\Models\BudgetSetting;
use App\Models\Category;
use App\Models\Pocket;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WatchedCategory;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/**
 * The design's October on the 15th: 450 000 income, 250 000 fixed + 45 000 savings, 70 000 spent before today.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00'));
    $this->user = User::factory()->create();
    BudgetSetting::factory()->for($this->user)->create(['period_mode' => PeriodMode::Calendar, 'income' => 450_000, 'locale' => 'hu', 'onboarded_at' => now()]);
    $rent = Category::factory()->for($this->user)->create(['name' => 'Lakbér', 'type' => LineType::Fixed]);
    BudgetLine::factory()->for($this->user)->for($rent)->create(['amount' => 212_000, 'due_day' => 15]);
    $bills = Category::factory()->for($this->user)->create(['name' => 'Rezsi', 'type' => LineType::Fixed]);
    $this->bills = BudgetLine::factory()->for($this->user)->for($bills)->create(['amount' => 38_000, 'due_day' => 15, 'manual_pay' => true]);
    $this->goal = Pocket::factory()->for($this->user)->create(['name' => 'Nyaralás', 'balance' => 100_000]);
    $savings = Category::factory()->for($this->user)->create(['name' => 'Félretétel · Nyaralás', 'type' => LineType::Sinking]);
    BudgetLine::factory()->for($this->user)->for($savings)->create(['amount' => 45_000, 'due_day' => 12, 'manual_pay' => true, 'pocket_id' => $this->goal->id]);
    $this->restaurant = Category::factory()->for($this->user)->create(['name' => 'Étterem', 'type' => LineType::Variable]);
    $this->period = resolve(PeriodService::class)->forDate($this->user, CarbonImmutable::parse('2026-10-15'));
    Transaction::factory()->for($this->user)->for($this->period)->create(['category_id' => $this->restaurant->id, 'amount' => 70_000, 'occurred_on' => '2026-10-14']);
    app()->setLocale('hu');
    $this->actingAs($this->user);
});

it('shows what the month still has, the allowance and the pace', function (): void {
    Livewire::test('pages::today')
        ->assertSeeInOrder(['Még erre a hónapra', money_number(85_000), 'Jó tempóban vagy', 'Napi keret', money_number(5_000)])
        ->assertSee('17 nap van hátra');
});

it('puts today\'s fixed lines on the screen with what to do', function (): void {
    Livewire::test('pages::today')
        ->assertSee('Ma megy le: Lakbér')
        ->assertSee('előre levonva, nincs teendő')
        ->assertSee('Ma esedékes: Rezsi')
        ->assertSee('Még nincs félretéve: Félretétel · Nyaralás');
});

it('marks a savings line set aside and adds it to its goal', function (): void {
    Livewire::test('pages::today')
        ->call('markDone', BudgetLine::query()->where('pocket_id', $this->goal->id)->value('id'))
        ->assertDispatched('app-toast')
        ->assertDontSee('Még nincs félretéve');

    expect($this->goal->fresh()->balance)->toBe(145_000);
});

it('counts another amount for this month', function (): void {
    Livewire::test('pages::today')
        ->call('setAmount', $this->bills->id, '41200')
        ->assertReturned(['ok' => true, 'error' => null])
        ->assertSee(money_number(81_800));
});

it('refuses an empty amount', function (): void {
    Livewire::test('pages::today')
        ->call('setAmount', $this->bills->id, '')
        ->assertReturned(['ok' => false, 'error' => 'Adj meg egy összeget.']);
});

it('shows going over today and tomorrow\'s allowance', function (): void {
    Transaction::factory()->for($this->user)->for($this->period)->create(['category_id' => $this->restaurant->id, 'amount' => 7_400, 'occurred_on' => '2026-10-15']);

    Livewire::test('pages::today')
        ->assertSee(money_number(77_600))
        ->assertSee(money(2_400).'-tal túl')
        ->assertSee('Holnaptól napi '.money(4_850).'.')
        ->assertSee('Feljegyeztem. Jó, hogy beírtad.');
});

it('notes a watched category from 80%', function (): void {
    WatchedCategory::factory()->for($this->user)->create(['category_id' => $this->restaurant->id, 'amount' => 80_000]);

    Livewire::test('pages::today')
        ->assertSee('Étterem: még '.money(10_000))
        ->assertSee('Étterem: a szánt összeg 87%-a elment, még '.money(10_000).' van rá.');
});

it('marks a no-spend day', function (): void {
    Livewire::test('pages::today')
        ->call('markNoSpend')
        ->assertSee('Nyugis nap. A sorozat megy tovább.');
});

it('asks to close the month before', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00'));

    Livewire::test('pages::today')->assertSee('Véget ért Október');
});

it('opens for a signed-in user', function (): void {
    $this->get(route('dashboard'))->assertOk()->assertSee('Még erre a hónapra');
});
