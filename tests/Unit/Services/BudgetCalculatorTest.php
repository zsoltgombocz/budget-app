<?php

use App\Enums\CalcMode;
use App\Enums\LineType;
use App\Services\BudgetCalculator;
use App\Services\Data\PlanLine;

/**
 * @return list<PlanLine>
 */
function hufPlan(CalcMode $fuelMode): array
{
    return [
        new PlanLine(1, 1, 'Shared living', LineType::Transfer, 150_000),
        new PlanLine(2, 2, 'Personal loan', LineType::Loan, 87_549),
        new PlanLine(3, 3, 'Gym', LineType::Fixed, 15_000, dueDay: 5),
        new PlanLine(4, 4, 'iCloud', LineType::Fixed, 1_290, dueDay: 12),
        new PlanLine(5, 5, 'Fuel', LineType::Variable, 65_000, amountAvg: 65_000, amountMax: 80_000, calcMode: $fuelMode),
        new PlanLine(6, 6, 'Groceries', LineType::Variable, 120_000),
        new PlanLine(7, 7, 'Small things', LineType::Variable, 15_000),
        new PlanLine(8, 8, 'Protein', LineType::Sinking, 10_000),
    ];
}

/**
 * Amounts in euro cents.
 *
 * @return list<PlanLine>
 */
function eurPlan(CalcMode $groceryMode): array
{
    return [
        new PlanLine(1, 1, 'Joint account', LineType::Transfer, 80_000),
        new PlanLine(2, 2, 'Rent', LineType::Fixed, 120_000, dueDay: 1),
        new PlanLine(3, 3, 'Phone', LineType::Fixed, 2_499, dueDay: 15),
        new PlanLine(4, 4, 'Groceries', LineType::Variable, 45_000, amountAvg: 45_000, amountMax: 55_000, calcMode: $groceryMode),
        new PlanLine(5, 5, 'Fun', LineType::Variable, 15_000),
    ];
}

it('computes the planned leftover of a HUF plan', function (CalcMode $mode, int $expenses, int $leftover): void {
    $summary = new BudgetCalculator()->summarize(hufPlan($mode), 609_000);

    expect($summary->totalExpenses)->toBe($expenses)
        ->and($summary->leftover)->toBe($leftover)
        ->and($summary->totalFor(LineType::Transfer))->toBe(150_000)
        ->and($summary->totalFor(LineType::Loan))->toBe(87_549)
        ->and($summary->totalFor(LineType::Fixed))->toBe(16_290)
        ->and($summary->totalFor(LineType::Sinking))->toBe(10_000);
})->with([
    'average mode' => [CalcMode::Avg, 463_839, 145_161],
    'maximum mode' => [CalcMode::Max, 478_839, 130_161],
]);

it('computes the planned leftover of a EUR plan in cents', function (CalcMode $mode, int $leftover): void {
    $summary = new BudgetCalculator()->summarize(eurPlan($mode), 320_000);

    expect($summary->leftover)->toBe($leftover);
})->with([
    'average mode' => [CalcMode::Avg, 57_501],
    'maximum mode' => [CalcMode::Max, 47_501],
]);

it('falls back to the base amount when avg or max is missing', function (): void {
    $line = new PlanLine(1, 1, 'Fuel', LineType::Variable, 50_000, calcMode: CalcMode::Max);

    expect($line->planned())->toBe(50_000);
});

it('round-trips plan lines through a snapshot', function (): void {
    $calculator = new BudgetCalculator;
    $lines = hufPlan(CalcMode::Max);

    $restored = $calculator->fromSnapshot(json_decode((string) json_encode($calculator->snapshot($lines)), true));

    expect($restored)->toEqual($lines);
});

it('ignores malformed snapshot rows', function (): void {
    expect(new BudgetCalculator()->fromSnapshot(null))->toBe([])
        ->and(new BudgetCalculator()->fromSnapshot(['oops']))->toBe([]);
});
