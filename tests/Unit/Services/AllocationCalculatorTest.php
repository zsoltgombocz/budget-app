<?php

use App\Enums\LineType;
use App\Services\AllocationCalculator;
use App\Services\Data\PlanLine;
use App\Services\Data\PlanSummary;

it('fills the reserve up to its target and sends the rest to the surplus target', function (): void {
    $allocation = new AllocationCalculator()->allocate(100_000, 100, true, reserveBalance: 80_000, reserveTarget: 100_000);

    expect($allocation->toReserve)->toBe(20_000)
        ->and($allocation->toSurplus)->toBe(80_000);
});

it('only puts the configured share into the reserve', function (): void {
    $allocation = new AllocationCalculator()->allocate(100_000, 50, true, reserveBalance: 0, reserveTarget: 100_000);

    expect($allocation->toReserve)->toBe(50_000)
        ->and($allocation->toSurplus)->toBe(50_000);
});

it('treats a reserve without target as uncapped', function (): void {
    $allocation = new AllocationCalculator()->allocate(90_001, 100, true, reserveBalance: 1_000_000);

    expect($allocation->toReserve)->toBe(90_001)
        ->and($allocation->toSurplus)->toBe(0);
});

it('sends everything to the surplus target once the reserve is full', function (): void {
    $allocation = new AllocationCalculator()->allocate(40_000, 100, true, reserveBalance: 120_000, reserveTarget: 100_000);

    expect($allocation->toReserve)->toBe(0)
        ->and($allocation->toSurplus)->toBe(40_000);
});

it('covers a deficit from the reserve, never from an overdraft', function (): void {
    $allocation = new AllocationCalculator()->allocate(-30_000, 100, true, reserveBalance: 20_000);

    expect($allocation->fromReserve)->toBe(20_000)
        ->and($allocation->uncovered)->toBe(10_000)
        ->and($allocation->toReserve)->toBe(0)
        ->and($allocation->toSurplus)->toBe(0);
});

it('works without a reserve pocket', function (): void {
    $calculator = new AllocationCalculator;

    expect($calculator->allocate(50_000, 100, false)->toSurplus)->toBe(50_000)
        ->and($calculator->allocate(-5_000, 100, false)->uncovered)->toBe(5_000);
});

it('rejects an invalid percentage', function (): void {
    new AllocationCalculator()->allocate(1, 101, true);
})->throws(InvalidArgumentException::class);

it('shows the month end of a plan with the reserve saving on its own line', function (): void {
    $lines = [
        new PlanLine(lineId: 1, categoryId: 1, categoryName: 'Rent', type: LineType::Fixed, amount: 395_000),
        new PlanLine(lineId: 2, categoryId: 2, categoryName: 'Reserve', type: LineType::Sinking, amount: 25_000, pocketId: 7),
    ];
    $summary = new PlanSummary(income: 500_000, totalsByType: [], totalExpenses: 420_000, leftover: 80_000);

    $forecast = new AllocationCalculator()->monthEnd($summary, $lines, reservePct: 0, reservePocketId: 7, reserveBalance: 0, reserveTarget: 300_000);

    expect($forecast->planned)->toBe(395_000)
        ->and($forecast->reserveMonthly)->toBe(25_000)
        ->and($forecast->leftover)->toBe(80_000)
        ->and($forecast->allocation->toReserve)->toBe(0)
        ->and($forecast->allocation->toSurplus)->toBe(80_000)
        ->and($forecast->toReserveTotal())->toBe(25_000);
});

it('counts the monthly reserve saving towards the target before the leftover share', function (): void {
    $lines = [new PlanLine(lineId: 2, categoryId: 2, categoryName: 'Reserve', type: LineType::Sinking, amount: 25_000, pocketId: 7)];
    $summary = new PlanSummary(income: 105_000, totalsByType: [], totalExpenses: 25_000, leftover: 80_000);

    // 270 000 + 25 000 = 295 000 of the 300 000 target: only 5 000 more fits from the leftover.
    $forecast = new AllocationCalculator()->monthEnd($summary, $lines, reservePct: 50, reservePocketId: 7, reserveBalance: 270_000, reserveTarget: 300_000);

    expect($forecast->allocation->toReserve)->toBe(5_000)
        ->and($forecast->allocation->toSurplus)->toBe(75_000);
});

it('splits nothing when the plan is over the income', function (): void {
    $summary = new PlanSummary(income: 100_000, totalsByType: [], totalExpenses: 120_000, leftover: -20_000);

    $forecast = new AllocationCalculator()->monthEnd($summary, [], reservePct: 50, reservePocketId: null);

    expect($forecast->leftover)->toBe(-20_000)
        ->and($forecast->allocation->toReserve)->toBe(0)
        ->and($forecast->allocation->toSurplus)->toBe(0)
        ->and($forecast->hasReserve)->toBeFalse();
});
