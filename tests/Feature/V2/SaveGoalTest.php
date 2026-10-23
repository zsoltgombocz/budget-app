<?php

use App\Actions\Month\SaveGoal;
use App\Enums\LineType;
use App\Enums\PeriodMode;
use App\Models\BudgetSetting;
use App\Models\User;
use App\Services\MonthService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00'));
    $this->user = User::factory()->create();
    BudgetSetting::factory()->for($this->user)->create(['period_mode' => PeriodMode::Calendar, 'income' => 450_000, 'locale' => 'hu']);
    app()->setLocale('hu');
});

it('creates a goal with what is on it and the monthly savings line that feeds it', function (): void {
    $goal = resolve(SaveGoal::class)->create($this->user, ['name' => 'Nyaralás', 'target' => 300_000, 'target_month' => '2027-06', 'monthly' => 25_000, 'starting' => 100_000]);

    $line = $this->user->budgetLines()->with('category')->sole();

    expect($goal->balance)->toBe(100_000)
        ->and($goal->target_month->toDateString())->toBe('2027-06-01')
        ->and($line->pocket_id)->toBe($goal->id)
        ->and($line->manual_pay)->toBeTrue()
        ->and($line->category->type)->toBe(LineType::Sinking)
        ->and($line->category->name)->toBe('Félretétel · Nyaralás')
        ->and(resolve(MonthService::class)->current($this->user)->state->spendable)->toBe(425_000);
});

it('sets a goal to what is really on it, recording the difference', function (): void {
    $action = resolve(SaveGoal::class);
    $goal = $action->create($this->user, ['name' => 'Vésztartalék', 'target' => null, 'target_month' => null, 'monthly' => 0, 'starting' => 0]);

    $action->setBalance($this->user, $goal, 40_000);

    expect($goal->fresh()->balance)->toBe(40_000)
        ->and($this->user->pocketMovements()->sole()->amount)->toBe(40_000);
});

it('archives a goal, stops its savings line, and brings both back', function (): void {
    $action = resolve(SaveGoal::class);
    $goal = $action->create($this->user, ['name' => 'Autó', 'target' => 1_000_000, 'target_month' => null, 'monthly' => 20_000, 'starting' => 0]);

    $action->archive($this->user, $goal);

    expect($this->user->pockets()->count())->toBe(0)
        ->and(resolve(MonthService::class)->current($this->user)->fixedTotal)->toBe(0);

    $action->restore($this->user, $goal->id);

    expect($this->user->pockets()->count())->toBe(1)
        ->and(resolve(MonthService::class)->current($this->user)->fixedTotal)->toBe(20_000);
});

it('refuses a goal without a name', function (): void {
    resolve(SaveGoal::class)->create($this->user, ['name' => ' ', 'target' => null, 'target_month' => null, 'monthly' => 0, 'starting' => 0]);
})->throws(ValidationException::class);
