<?php

use App\Enums\LineType;
use App\Services\Data\PlanLine;
use App\Services\ForecastService;

function forecastPlan(): array
{
    return [
        new PlanLine(1, 1, 'Rent', LineType::Fixed, 200_000),
        new PlanLine(2, 2, 'Savings', LineType::Transfer, 100_000),
        new PlanLine(3, 3, 'Fuel', LineType::Variable, 60_000),
        new PlanLine(4, 4, 'Groceries', LineType::Variable, 90_000),
    ];
}

it('trusts the plan while spending stays within it', function (): void {
    $forecast = new ForecastService()->forecast(forecastPlan(), 500_000, [3 => 20_000], totalDays: 30, elapsedDays: 4);

    expect($forecast->expectedLeftover)->toBe(50_000)
        ->and($forecast->plannedLeftover)->toBe(50_000)
        ->and($forecast->committed)->toBe(300_000);
});

it('does not extrapolate a one-off purchase over the rest of the period', function (): void {
    $forecast = new ForecastService()->forecast(forecastPlan(), 500_000, [3 => 91_000, 4 => 10_000], totalDays: 31, elapsedDays: 5);

    // Fuel counts with what was spent (91 000), not 91 000 × 31 / 5; groceries stay at the 90 000 plan.
    expect($forecast->variableExpected)->toBe(181_000)
        ->and($forecast->expectedLeftover)->toBe(19_000);
});

it('never expects less than what was already spent', function (): void {
    $service = new ForecastService;

    expect($service->expectedSpend(planned: 60_000, spent: 70_000))->toBe(70_000)
        ->and($service->expectedSpend(planned: 60_000, spent: 20_000))->toBe(60_000);
});

it('includes spending in categories without a plan line', function (): void {
    $forecast = new ForecastService()->forecast(forecastPlan(), 500_000, [99 => 5_000], 30, 2, [99 => 'Gift']);

    $gift = collect($forecast->categories)->firstWhere('categoryId', 99);

    expect($gift->categoryName)->toBe('Gift')
        ->and($gift->planned)->toBe(0)
        ->and($gift->isOver())->toBeTrue()
        ->and($forecast->expectedLeftover)->toBe(45_000);
});

it('flags categories at 80 and 100 percent', function (): void {
    $forecast = new ForecastService()->forecast(forecastPlan(), 500_000, [3 => 50_000, 4 => 95_000], 30, 1);
    $byId = collect($forecast->categories)->keyBy('categoryId');

    expect($byId[3]->isWarning())->toBeTrue()
        ->and($byId[3]->isOver())->toBeFalse()
        ->and($byId[4]->isOver())->toBeTrue();
});

it('spreads the remaining variable budget over the remaining days', function (): void {
    $forecast = new ForecastService()->forecast(forecastPlan(), 500_000, [3 => 30_000, 4 => 20_000], totalDays: 30, elapsedDays: 10);

    // (150 000 − 50 000) / 21 remaining days, today included.
    expect($forecast->remainingDays)->toBe(21)
        ->and($forecast->dailyAllowance)->toBe(4_761);
});

it('has no daily allowance once the budget is used up', function (): void {
    expect(new ForecastService()->dailyAllowance(100, 200, 5))->toBe(0)
        ->and(new ForecastService()->dailyAllowance(100, 0, 0))->toBe(0);
});

it('does not count spending in a fixed category on top of its planned amount', function (): void {
    $forecast = new ForecastService()->forecast(forecastPlan(), 500_000, [1 => 40_000, 3 => 20_000], totalDays: 30, elapsedDays: 10);

    expect($forecast->committed)->toBe(300_000)
        ->and($forecast->variableSpent)->toBe(20_000)
        ->and($forecast->expectedLeftover)->toBe(50_000)
        ->and(collect($forecast->categories)->pluck('categoryId')->all())->toBe([3, 4]);
});

it('counts a fixed item marked as not paid as 0', function (): void {
    $lines = forecastPlan();
    $lines[0] = $lines[0]->asSkipped();

    $forecast = new ForecastService()->forecast($lines, 500_000, [], totalDays: 30, elapsedDays: 4);

    expect($forecast->committed)->toBe(100_000)
        ->and($forecast->expectedLeftover)->toBe(250_000)
        ->and($lines[0]->planned())->toBe(200_000)
        ->and($lines[0]->counted())->toBe(0);
});

it('lets only a plain fixed item be marked as not paid', function (): void {
    expect(new PlanLine(1, 1, 'Rent', LineType::Fixed, 200_000)->isSkippable())->toBeTrue()
        ->and(new PlanLine(2, 2, 'Shared account', LineType::Transfer, 100_000)->isSkippable())->toBeTrue()
        ->and(new PlanLine(3, 3, 'Fuel', LineType::Variable, 60_000)->isSkippable())->toBeFalse()
        ->and(new PlanLine(4, 4, 'Holiday', LineType::Sinking, 20_000, pocketId: 7)->isSkippable())->toBeFalse()
        ->and(new PlanLine(5, 5, 'Car loan', LineType::Loan, 40_000, loanId: 3)->isSkippable())->toBeFalse()
        ->and(new PlanLine(null, 6, 'Old', LineType::Fixed, 1_000)->isSkippable())->toBeFalse();
});
