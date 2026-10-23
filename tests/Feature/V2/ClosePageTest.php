<?php

use App\Enums\CloseDecision;
use App\Enums\LineType;
use App\Enums\PeriodMode;
use App\Enums\PeriodStatus;
use App\Models\BudgetLine;
use App\Models\BudgetSetting;
use App\Models\Category;
use App\Models\Pocket;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 20:00'));
    $this->user = User::factory()->create();
    BudgetSetting::factory()->for($this->user)->create(['period_mode' => PeriodMode::Calendar, 'income' => 450_000, 'locale' => 'hu', 'onboarded_at' => now()]);
    $fixed = Category::factory()->for($this->user)->create(['name' => 'Fix', 'type' => LineType::Fixed]);
    BudgetLine::factory()->for($this->user)->for($fixed)->create(['amount' => 250_000]);
    $this->goal = Pocket::factory()->for($this->user)->create(['name' => 'Nyaralás', 'balance' => 100_000]);
    $savings = Category::factory()->for($this->user)->create(['name' => 'Félretétel · Nyaralás', 'type' => LineType::Sinking]);
    $this->savings = BudgetLine::factory()->for($this->user)->for($savings)->create(['amount' => 45_000, 'due_day' => 12, 'manual_pay' => true, 'pocket_id' => $this->goal->id]);
    $this->period = resolve(PeriodService::class)->forDate($this->user, CarbonImmutable::parse('2026-10-15'));
    $this->shop = Category::factory()->for($this->user)->create(['name' => 'Bolt', 'type' => LineType::Variable]);
    app()->setLocale('hu');
    $this->actingAs($this->user);
});

function spendAtClose(object $test, int $amount): void
{
    Transaction::factory()->for($test->user)->for($test->period)->create(['category_id' => $test->shop->id, 'amount' => $amount, 'occurred_on' => '2026-10-20']);
}

it('shows what was left and offers one decision', function (): void {
    spendAtClose($this, 136_600);

    Livewire::test('pages::close', ['period' => $this->period->id])
        ->assertSee('Októberben')
        ->assertSee(money(18_400))
        ->assertSee('maradt.')
        ->assertSeeInOrder(['Félreteszem', 'A Nyaralás cél állása '.money(18_400).'-tal nő.', 'Viszem novemberbe', 'Novemberben '.money(18_400).'-tal több a költőpénz.', 'Csak feljegyzem'])
        ->assertSee('A Nyaralás '.money(45_000).'-ját félretetted?')
        ->assertSet('decision', 'save');
});

it('closes with the leftover on the goal and sends to Today with an undo', function (): void {
    spendAtClose($this, 136_600);

    Livewire::test('pages::close', ['period' => $this->period->id])
        ->call('close')
        ->assertDispatched('app-toast', fn (string $name, array $params): bool => $params['undo'] === 'undo-close' && $params['title'] === 'Kész, jöhet a november')
        ->assertRedirect(route('dashboard'));

    expect($this->period->fresh()->status)->toBe(PeriodStatus::Closed)
        ->and($this->goal->fresh()->balance)->toBe(118_400);
});

it('carries the leftover into next month', function (): void {
    spendAtClose($this, 136_600);

    Livewire::test('pages::close', ['period' => $this->period->id])->set('decision', 'carry')->call('close');

    expect($this->user->periods()->whereDate('starts_on', '2026-11-01')->sole()->carried_over)->toBe(18_400);
});

it('splits the leftover between the goal and next month', function (): void {
    spendAtClose($this, 136_600);

    Livewire::test('pages::close', ['period' => $this->period->id])
        ->call('setToGoal', '10000')
        ->assertReturned(['ok' => true, 'error' => null])
        ->assertSee(money(8_400).' megy novemberbe.')
        ->call('close');

    expect($this->goal->fresh()->balance)->toBe(110_000)
        ->and($this->user->periods()->whereDate('starts_on', '2026-11-01')->sole()->carried_over)->toBe(8_400);
});

it('offers the deficit choices for a real minus', function (): void {
    spendAtClose($this, 167_000);

    Livewire::test('pages::close', ['period' => $this->period->id])
        ->assertSee('Októberben '.money(12_000).'-tal többet költöttél, mint amennyi volt.')
        ->assertSeeInOrder(['Levonjuk novemberből', 'Megoldottam máshonnan', 'Célból fedezem'])
        ->assertSet('decision', CloseDecision::Deduct->value);
});

it('counts the income that actually came in', function (): void {
    spendAtClose($this, 136_600);

    Livewire::test('pages::close', ['period' => $this->period->id])
        ->call('setIncome', '464000')
        ->assertSee(money(32_400));
});

it('sets the savings aside when asked', function (): void {
    spendAtClose($this, 136_600);

    Livewire::test('pages::close', ['period' => $this->period->id])
        ->call('confirmSavings', $this->savings->id)
        ->assertDontSee('-ját félretetted?');

    expect($this->goal->fresh()->balance)->toBe(145_000);
});

it('says when the month cannot be closed yet', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 10:00'));

    Livewire::test('pages::close', ['period' => $this->period->id])->assertSee('Még nem');
});
