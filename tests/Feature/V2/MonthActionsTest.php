<?php

use App\Actions\Month\MarkLineDone;
use App\Actions\Month\RecordExtraIncome;
use App\Actions\Month\SetLineThisMonth;
use App\Enums\LineType;
use App\Enums\PeriodMode;
use App\Enums\PeriodStatus;
use App\Models\BudgetLine;
use App\Models\BudgetSetting;
use App\Models\Category;
use App\Models\Pocket;
use App\Models\User;
use App\Services\MonthService;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00'));
    $this->user = User::factory()->create();
    BudgetSetting::factory()->for($this->user)->create(['period_mode' => PeriodMode::Calendar, 'income' => 450_000]);
    $this->goal = Pocket::factory()->for($this->user)->create(['name' => 'Nyaralás', 'balance' => 100_000]);
    $savings = Category::factory()->for($this->user)->create(['name' => 'Félretétel · Nyaralás', 'type' => LineType::Sinking]);
    $this->savings = BudgetLine::factory()->for($this->user)->for($savings)->create(['amount' => 45_000, 'due_day' => 12, 'manual_pay' => true, 'pocket_id' => $this->goal->id]);
    $bills = Category::factory()->for($this->user)->create(['name' => 'Rezsi', 'type' => LineType::Fixed]);
    $this->bills = BudgetLine::factory()->for($this->user)->for($bills)->create(['amount' => 38_000, 'due_day' => 15, 'manual_pay' => true]);
    $this->period = resolve(PeriodService::class)->forDate($this->user, CarbonImmutable::parse('2026-10-12'));
});

function monthTotals(object $test): array
{
    $view = resolve(MonthService::class)->view($test->user, $test->period->refresh(), CarbonImmutable::parse('2026-10-12'));

    return [$view->fixedTotal, $view->state->remaining];
}

it('adds a savings line to its goal only on "Félretettem", without changing the month', function (): void {
    $before = monthTotals($this);

    expect(resolve(MarkLineDone::class)->mark($this->user, $this->period, $this->savings->id))->toBe(45_000)
        ->and($this->goal->fresh()->balance)->toBe(145_000)
        ->and(monthTotals($this))->toBe($before);

    // A second tap does not add again.
    expect(resolve(MarkLineDone::class)->mark($this->user, $this->period, $this->savings->id))->toBeNull()
        ->and($this->goal->fresh()->balance)->toBe(145_000);
});

it('takes the goal\'s money back on undo', function (): void {
    $action = resolve(MarkLineDone::class);
    $action->mark($this->user, $this->period, $this->savings->id);
    $action->undo($this->user, $this->period, $this->savings->id);

    expect($this->goal->fresh()->balance)->toBe(100_000)
        ->and($this->user->pocketMovements()->count())->toBe(0)
        ->and($this->period->lineStatuses()->first()->paid_on)->toBeNull();
});

it('marks a bill paid by hand without touching any goal', function (): void {
    expect(resolve(MarkLineDone::class)->mark($this->user, $this->period, $this->bills->id))->toBeNull()
        ->and($this->period->lineStatuses()->first()->paid_on->toDateString())->toBe('2026-10-12')
        ->and($this->goal->fresh()->balance)->toBe(100_000);
});

it('counts a different amount, then the own amount again', function (): void {
    $action = resolve(SetLineThisMonth::class);
    $action->amount($this->user, $this->period, $this->bills->id, 41_200);

    expect(monthTotals($this)[0])->toBe(86_200);

    $action->amount($this->user, $this->period, $this->bills->id, 38_000);

    expect(monthTotals($this)[0])->toBe(83_000)
        ->and($this->period->lineStatuses()->first()->amount_actual)->toBeNull();
});

it('leaves a line out of this month and back', function (): void {
    $action = resolve(SetLineThisMonth::class);
    $action->skip($this->user, $this->period, $this->bills->id);

    expect(monthTotals($this)[0])->toBe(45_000);

    $action->skip($this->user, $this->period, $this->bills->id, false);

    expect(monthTotals($this)[0])->toBe(83_000);
});

it('adds extra income to the month at once and is idempotent per client id', function (): void {
    $action = resolve(RecordExtraIncome::class);
    $action->record($this->user, 15_000, clientUuid: 'a6b0b6d2-9a8c-4c9e-9a51-5c8f6c0a1111');
    $action->record($this->user, 15_000, clientUuid: 'a6b0b6d2-9a8c-4c9e-9a51-5c8f6c0a1111');

    expect($this->user->extraIncomes()->count())->toBe(1)
        ->and(monthTotals($this)[1])->toBe(450_000 - 83_000 + 15_000);
});

it('refuses extra income without an amount or in a closed month', function (): void {
    $action = resolve(RecordExtraIncome::class);

    expect(fn () => $action->record($this->user, 0))->toThrow(ValidationException::class);

    $this->period->update(['status' => PeriodStatus::Closed]);

    expect(fn () => $action->record($this->user, 5_000))->toThrow(ValidationException::class);
});

it('only lets the owner touch a line', function (): void {
    $other = User::factory()->create();

    resolve(MarkLineDone::class)->mark($other, $this->period, $this->savings->id);
})->throws(ModelNotFoundException::class);
