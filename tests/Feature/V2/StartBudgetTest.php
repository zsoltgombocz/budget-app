<?php

use App\Actions\Month\StartBudget;
use App\Enums\LineType;
use App\Enums\PeriodMode;
use App\Models\BudgetSetting;
use App\Models\User;
use App\Services\MonthService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00'));
    $this->user = User::factory()->create();
    BudgetSetting::factory()->for($this->user)->create(['onboarded_at' => null, 'locale' => 'hu']);
    app()->setLocale('hu');
});

it('builds the 2.0 plan from the answers and opens the month', function (): void {
    resolve(StartBudget::class)->handle($this->user, 450_000, PeriodMode::Calendar, null, [
        ['name' => 'Lakhatás', 'icon' => 'home', 'amount' => 150_000, 'due_day' => 10],
        ['name' => 'Rezsi', 'amount' => 38_000, 'due_day' => 15],
        ['name' => 'Hitel', 'amount' => 62_000, 'loan' => true],
        ['name' => 'Telefon', 'amount' => 0],
    ], ['amount' => 45_000, 'goal' => 'Nyaralás']);

    $view = resolve(MonthService::class)->current($this->user->refresh());

    expect($this->user->settings()->onboarded_at)->not->toBeNull()
        ->and($view->state->spendable)->toBe(155_000)
        ->and($view->state->dailyAllowance)->toBe(5_000)
        ->and($this->user->budgetLines()->whereRelation('category', 'type', LineType::Loan)->count())->toBe(1)
        ->and($this->user->pockets()->sole()->name)->toBe('Nyaralás')
        ->and($this->user->categories()->where('type', LineType::Variable)->count())->toBe(count(StartBudget::CATEGORIES))
        ->and($this->user->categories()->where('type', LineType::Variable)->orderBy('sort')->value('name'))->toBe('Bolt');
});

it('needs an income and a payday for a payday month', function (): void {
    expect(fn () => resolve(StartBudget::class)->handle($this->user, 0, PeriodMode::Calendar, null, [], ['amount' => 0]))->toThrow(ValidationException::class)
        ->and(fn () => resolve(StartBudget::class)->handle($this->user, 450_000, PeriodMode::Payday, null, [], ['amount' => 0]))->toThrow(ValidationException::class);
});
