<?php

use App\Enums\DueState;
use App\Enums\LineType;
use App\Services\Data\MonthLine;
use App\Services\FixedLines;
use Carbon\CarbonImmutable;

/**
 * The design's October fixed lines (S24), on a given day.
 *
 * @return list<MonthLine>
 */
function octoberLines(array $marked = []): array
{
    $on = fn (int $day): CarbonImmutable => CarbonImmutable::create(2026, 10, $day);

    return [
        new MonthLine(1, 'Lakbér', LineType::Fixed, 150_000, $on(10)),
        new MonthLine(2, 'Rezsi', LineType::Fixed, 38_000, $on(15), manualPay: true, done: in_array(2, $marked, true)),
        new MonthLine(3, 'Telefon és net', LineType::Fixed, 9_000, $on(3)),
        new MonthLine(4, 'Előfizetések', LineType::Fixed, 6_050, $on(1)),
        new MonthLine(5, 'Közlekedési bérlet', LineType::Fixed, 8_950, $on(1)),
        new MonthLine(6, 'Edzőterem', LineType::Fixed, 5_000, $on(5)),
        new MonthLine(7, 'Közös kassza', LineType::Fixed, 25_000),
        new MonthLine(8, 'Biztosítás', LineType::Fixed, 8_000),
        new MonthLine(9, 'Félretétel · Nyaralás', LineType::Sinking, 45_000, $on(12), manualPay: true, done: in_array(9, $marked, true), goalId: 1),
    ];
}

function stateOf(array $lines, int $lineId): DueState
{
    foreach ($lines as $line) {
        if ($line->lineId === $lineId) {
            return $line->state;
        }
    }

    throw new RuntimeException("No line {$lineId}");
}

it('counts the lines with a day that have gone out', function (): void {
    $service = new FixedLines;
    $lines = $service->states(octoberLines(), CarbonImmutable::create(2026, 10, 10));

    expect(stateOf($lines, 1))->toBe(DueState::DueToday)
        ->and(stateOf($lines, 3))->toBe(DueState::Deducted)
        ->and(stateOf($lines, 2))->toBe(DueState::Upcoming)
        ->and(stateOf($lines, 7))->toBe(DueState::NoDay)
        ->and($service->summary($lines))->toBe(['withDay' => 7, 'settled' => 5, 'noDay' => 2]);
});

it('asks for a tap only on lines paid by hand, and keeps asking quietly after the day', function (): void {
    $service = new FixedLines;

    expect(stateOf($service->states(octoberLines(), CarbonImmutable::create(2026, 10, 15)), 2))->toBe(DueState::ToDo)
        ->and(stateOf($service->states(octoberLines(), CarbonImmutable::create(2026, 10, 16)), 2))->toBe(DueState::Overdue)
        ->and(stateOf($service->states(octoberLines([2]), CarbonImmutable::create(2026, 10, 16)), 2))->toBe(DueState::Done);
});

it('puts what needs a look first', function (): void {
    $lines = new FixedLines()->states(octoberLines(), CarbonImmutable::create(2026, 10, 12));

    expect(array_map(fn (MonthLine $line): string => $line->name, array_slice($lines, 0, 2)))->toBe(['Félretétel · Nyaralás', 'Rezsi']);
});

it('leaves a skipped line out of the count and the month', function (): void {
    $line = new MonthLine(5, 'Közlekedési bérlet', LineType::Fixed, 8_950, CarbonImmutable::create(2026, 10, 1), skipped: true);
    $service = new FixedLines;
    $lines = $service->states([$line], CarbonImmutable::create(2026, 10, 10));

    expect($lines[0]->state)->toBe(DueState::Skipped)
        ->and($lines[0]->counted())->toBe(0)
        ->and($service->summary($lines))->toBe(['withDay' => 0, 'settled' => 0, 'noDay' => 0]);
});
