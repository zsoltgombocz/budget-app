<?php

use App\Actions\Budget\ReopenPeriod;
use App\Actions\Month\CloseMonth;
use App\Actions\Month\MarkLineDone;
use App\Enums\CloseDecision;
use App\Enums\LineType;
use App\Enums\PeriodMode;
use App\Enums\PeriodStatus;
use App\Models\BudgetLine;
use App\Models\BudgetSetting;
use App\Models\Category;
use App\Models\Period;
use App\Models\Pocket;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Data\MonthLine;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-31 20:00'));
    $this->user = User::factory()->create();
    BudgetSetting::factory()->for($this->user)->create(['period_mode' => PeriodMode::Calendar, 'income' => 450_000]);
    $fixed = Category::factory()->for($this->user)->create(['name' => 'Fix', 'type' => LineType::Fixed]);
    BudgetLine::factory()->for($this->user)->for($fixed)->create(['amount' => 250_000]);
    $this->goal = Pocket::factory()->for($this->user)->create(['name' => 'Nyaralás', 'balance' => 100_000]);
    $savings = Category::factory()->for($this->user)->create(['name' => 'Félretétel · Nyaralás', 'type' => LineType::Sinking]);
    $this->savings = BudgetLine::factory()->for($this->user)->for($savings)->create(['amount' => 45_000, 'due_day' => 12, 'manual_pay' => true, 'pocket_id' => $this->goal->id]);
    $this->period = resolve(PeriodService::class)->forDate($this->user, CarbonImmutable::parse('2026-10-15'));
    $this->groceries = Category::factory()->for($this->user)->create(['name' => 'Bolt', 'type' => LineType::Variable]);
});

function spendOctober(object $test, int $amount): void
{
    Transaction::factory()->for($test->user)->for($test->period)->create(['category_id' => $test->groceries->id, 'amount' => $amount, 'occurred_on' => '2026-10-20']);
}

function november(object $test): Period
{
    return $test->user->periods()->whereDate('starts_on', '2026-11-01')->sole();
}

it('works out the leftover and asks about savings not marked yet', function (): void {
    spendOctober($this, 136_600);

    $summary = resolve(CloseMonth::class)->summary($this->user, $this->period);

    expect($summary->leftover())->toBe(18_400)
        ->and($summary->savingsTotal)->toBe(45_000)
        ->and(array_map(fn (MonthLine $line): string => $line->name, $summary->unconfirmedSavings))->toBe(['Félretétel · Nyaralás'])
        ->and($summary->decisions(hasGoal: true))->toBe([CloseDecision::Save, CloseDecision::Carry, CloseDecision::Note]);

    resolve(MarkLineDone::class)->mark($this->user, $this->period, $this->savings->id);

    expect(resolve(CloseMonth::class)->summary($this->user, $this->period)->unconfirmedSavings)->toBe([]);
});

it('puts the leftover on a goal', function (): void {
    spendOctober($this, 136_600);

    $close = resolve(CloseMonth::class)->handle($this->user, $this->period, CloseDecision::Save, $this->goal->id);

    expect($close->to_goal)->toBe(18_400)
        ->and($close->carried_over)->toBe(0)
        ->and($this->goal->fresh()->balance)->toBe(118_400)
        ->and($this->period->fresh()->status)->toBe(PeriodStatus::Closed)
        ->and(november($this)->carried_over)->toBe(0);
});

it('splits the leftover between a goal and next month', function (): void {
    spendOctober($this, 136_600);

    $close = resolve(CloseMonth::class)->handle($this->user, $this->period, CloseDecision::Save, $this->goal->id, toGoal: 10_000);

    expect($close->to_goal)->toBe(10_000)
        ->and($this->goal->fresh()->balance)->toBe(110_000)
        ->and(november($this)->carried_over)->toBe(8_400);
});

it('carries the leftover into next month', function (): void {
    spendOctober($this, 136_600);

    resolve(CloseMonth::class)->handle($this->user, $this->period, CloseDecision::Carry);

    expect(november($this)->carried_over)->toBe(18_400)
        ->and($this->goal->fresh()->balance)->toBe(100_000);
});

it('only notes the leftover', function (): void {
    spendOctober($this, 136_600);

    resolve(CloseMonth::class)->handle($this->user, $this->period, CloseDecision::Note);

    expect(november($this)->carried_over)->toBe(0);
});

it('deducts a deficit from next month or covers it from a goal', function (CloseDecision $decision, int $carried, int $balance): void {
    spendOctober($this, 167_000);

    $close = resolve(CloseMonth::class)->handle($this->user, $this->period, $decision, $this->goal->id);

    expect($close->leftover)->toBe(-12_000)
        ->and(november($this)->carried_over)->toBe($carried)
        ->and($this->goal->fresh()->balance)->toBe($balance);
})->with([
    'deduct' => [CloseDecision::Deduct, -12_000, 100_000],
    'covered elsewhere' => [CloseDecision::Covered, 0, 100_000],
    'from the goal' => [CloseDecision::FromGoal, 0, 88_000],
]);

it('refuses a decision that does not fit the leftover', function (): void {
    spendOctober($this, 167_000);

    resolve(CloseMonth::class)->handle($this->user, $this->period, CloseDecision::Carry);
})->throws(ValidationException::class);

it('counts the actual income given at closing', function (): void {
    spendOctober($this, 136_600);

    $close = resolve(CloseMonth::class)->handle($this->user, $this->period, CloseDecision::Carry, incomeActual: 464_000);

    expect($close->leftover)->toBe(32_400)
        ->and(november($this)->carried_over)->toBe(32_400);
});

it('is undone with the goal and next month put back', function (): void {
    spendOctober($this, 136_600);
    resolve(CloseMonth::class)->handle($this->user, $this->period, CloseDecision::Save, $this->goal->id, toGoal: 10_000);
    Transaction::factory()->for($this->user)->for(november($this))->create(['category_id' => $this->groceries->id, 'amount' => 1_000, 'occurred_on' => '2026-11-03']);
    $this->travelTo(CarbonImmutable::parse('2026-10-31 21:00'));

    resolve(ReopenPeriod::class)->handle($this->user, $this->period);

    expect($this->period->fresh()->status)->toBe(PeriodStatus::Open)
        ->and($this->goal->fresh()->balance)->toBe(100_000)
        ->and(november($this)->carried_over)->toBe(0);
});
