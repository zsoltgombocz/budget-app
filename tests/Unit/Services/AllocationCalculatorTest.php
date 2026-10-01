<?php

use App\Services\AllocationCalculator;

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
