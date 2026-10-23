<?php

use App\Enums\DueState;
use App\Enums\LineType;
use App\Enums\PeriodMode;
use App\Models\BudgetLine;
use App\Models\BudgetSetting;
use App\Models\Category;
use App\Models\ExtraIncome;
use App\Models\PeriodLineStatus;
use App\Models\Pocket;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Data\MonthLine;
use App\Services\MonthService;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;

function monthLine(User $user, LineType $type, string $name, int $amount, array $attributes = []): BudgetLine
{
    $category = Category::factory()->for($user)->create(['name' => $name, 'type' => $type]);

    return BudgetLine::factory()->for($user)->for($category)->create(['amount' => $amount, ...$attributes]);
}

beforeEach(function (): void {
    $this->user = User::factory()->create();
    BudgetSetting::factory()->for($this->user)->create(['period_mode' => PeriodMode::Calendar, 'income' => 450_000]);
    $this->rent = monthLine($this->user, LineType::Fixed, 'Lakbér', 150_000, ['due_day' => 10]);
    $this->bills = monthLine($this->user, LineType::Fixed, 'Rezsi', 38_000, ['due_day' => 15, 'manual_pay' => true]);
    monthLine($this->user, LineType::Fixed, 'Többi', 62_000);
    $this->goal = Pocket::factory()->for($this->user)->create(['name' => 'Nyaralás']);
    $this->savings = monthLine($this->user, LineType::Sinking, 'Félretétel · Nyaralás', 45_000, ['due_day' => 12, 'manual_pay' => true, 'pocket_id' => $this->goal->id]);
    $this->groceries = Category::factory()->for($this->user)->create(['name' => 'Bolt', 'type' => LineType::Variable]);
    $this->period = resolve(PeriodService::class)->forDate($this->user, CarbonImmutable::parse('2026-10-01'));
});

function spend(object $test, string $date, int $amount): void
{
    Transaction::factory()->for($test->user)->for($test->period)->create(['category_id' => $test->groceries->id, 'amount' => $amount, 'occurred_on' => $date]);
}

it('shows what the month has left on a day', function (): void {
    spend($this, '2026-10-05', 70_000);
    spend($this, '2026-10-15', 2_000);

    $view = resolve(MonthService::class)->view($this->user, $this->period, CarbonImmutable::parse('2026-10-15'));

    expect($view->fixedTotal)->toBe(295_000)
        ->and($view->state->spendable)->toBe(155_000)
        ->and($view->state->remaining)->toBe(83_000)
        ->and($view->state->daysLeft)->toBe(17)
        ->and($view->state->dailyAllowance)->toBe(5_000)
        ->and($view->state->todayLeft)->toBe(3_000)
        ->and($view->spentByCategory)->toBe([$this->groceries->id => 72_000]);
});

it('gives each fixed line its state and puts what needs a tap on Today', function (): void {
    PeriodLineStatus::factory()->for($this->user)->for($this->period)->create(['budget_line_id' => $this->savings->id, 'paid_on' => '2026-10-12']);

    $view = resolve(MonthService::class)->view($this->user, $this->period, CarbonImmutable::parse('2026-10-15'));
    $states = collect($view->lines)->mapWithKeys(fn ($line): array => [$line->name => $line->state])->all();

    expect($states)->toMatchArray([
        'Rezsi' => DueState::ToDo,
        'Lakbér' => DueState::Deducted,
        'Félretétel · Nyaralás' => DueState::Done,
        'Többi' => DueState::NoDay,
    ])
        ->and($view->fixedSummary)->toBe(['withDay' => 3, 'settled' => 2, 'noDay' => 1])
        ->and(array_map(fn (MonthLine $line): string => $line->name, $view->todayRows()))->toBe(['Rezsi'])
        ->and(collect($view->lines)->firstWhere('name', 'Félretétel · Nyaralás')->goalId)->toBe($this->goal->id);
});

it('counts a different amount and a skipped line for this month only', function (): void {
    PeriodLineStatus::factory()->for($this->user)->for($this->period)->create(['budget_line_id' => $this->bills->id, 'amount_actual' => 41_200]);
    PeriodLineStatus::factory()->for($this->user)->for($this->period)->create(['budget_line_id' => $this->rent->id, 'skipped_on' => '2026-10-02']);

    $view = resolve(MonthService::class)->view($this->user, $this->period, CarbonImmutable::parse('2026-10-15'));

    expect($view->fixedTotal)->toBe(295_000 + 3_200 - 150_000);
});

it('adds extra income and money carried over at once', function (): void {
    $this->period->update(['carried_over' => 18_400]);
    ExtraIncome::factory()->for($this->user)->for($this->period)->create(['amount' => 15_000, 'occurred_on' => '2026-10-15']);
    spend($this, '2026-10-05', 70_000);

    $view = resolve(MonthService::class)->view($this->user, $this->period->refresh(), CarbonImmutable::parse('2026-10-15'));

    expect($view->state->remaining)->toBe(85_000 + 15_000 + 18_400)
        ->and($view->extraIncome)->toBe(15_000);
});
