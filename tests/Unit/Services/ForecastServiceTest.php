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

it('trusts the plan before the fifth day', function (): void {
    $forecast = new ForecastService()->forecast(forecastPlan(), 500_000, [3 => 20_000], totalDays: 30, elapsedDays: 4);

    expect($forecast->expectedLeftover)->toBe(50_000)
        ->and($forecast->plannedLeftover)->toBe(50_000)
        ->and($forecast->committed)->toBe(300_000);
});

it('projects spending linearly from the fifth day', function (): void {
    $forecast = new ForecastService()->forecast(forecastPlan(), 500_000, [3 => 30_000, 4 => 10_000], totalDays: 30, elapsedDays: 10);

    // Fuel: 30 000 × 30 / 10 = 90 000 > 60 000 plan; groceries projection 30 000 < 90 000 plan.
    expect($forecast->variableExpected)->toBe(180_000)
        ->and($forecast->expectedLeftover)->toBe(20_000);
});

it('never expects less than what was already spent', function (): void {
    $service = new ForecastService;

    expect($service->expectedSpend(planned: 60_000, spent: 70_000, totalDays: 30, elapsedDays: 2))->toBe(70_000)
        ->and($service->expectedSpend(planned: 60_000, spent: 70_000, totalDays: 30, elapsedDays: 30))->toBe(70_000);
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
