<?php

use App\Enums\PrepayMode;
use App\Services\LoanCalculator;
use App\Services\LoanPlanner;
use Carbon\CarbonImmutable;

it('ends sooner and saves interest with a monthly prepayment saving', function (): void {
    // The design's car loan: 3 200 000 at 9.9%, 72 600 a month, 55 months; 30 000 a month, prepaid in 500 000 steps.
    $plan = new LoanPlanner(new LoanCalculator)->plan(3_200_000, 9.9, 72_600, 55, 30_000, 500_000, PrepayMode::ReduceTerm, CarbonImmutable::parse('2026-11-01'));

    expect($plan->endWithout->format('Y-m'))->toBe('2031-05')
        ->and($plan->endWith->lessThan($plan->endWithout))->toBeTrue()
        ->and($plan->monthsSooner())->toBeGreaterThanOrEqual(12)
        ->and($plan->savedInterest())->toBeGreaterThan(100_000)
        ->and($plan->prepayments[0]->format('Y-m'))->toBe('2028-03');
});

it('lowers the installment instead when asked', function (): void {
    $plan = new LoanPlanner(new LoanCalculator)->plan(3_200_000, 9.9, 72_600, 55, 30_000, 500_000, PrepayMode::ReduceInstallment, CarbonImmutable::parse('2026-11-01'));

    expect($plan->installmentAfterFirst)->toBeLessThan(72_600)
        ->and($plan->savedInterest())->toBeGreaterThan(0);
});

it('changes nothing without a saving', function (): void {
    $plan = new LoanPlanner(new LoanCalculator)->plan(3_200_000, 9.9, 72_600, 55, 0, 500_000, PrepayMode::ReduceTerm, CarbonImmutable::parse('2026-11-01'));

    expect($plan->endWith->equalTo($plan->endWithout))->toBeTrue()
        ->and($plan->savedInterest())->toBe(0)
        ->and($plan->prepayments)->toBe([]);
});

it('counts what is set aside already towards the next prepayment', function (): void {
    $plan = new LoanPlanner(new LoanCalculator)->plan(3_200_000, 9.9, 72_600, 55, 30_000, 500_000, PrepayMode::ReduceTerm, CarbonImmutable::parse('2026-11-01'), 340_000);

    expect($plan->prepayments[0]->format('Y-m'))->toBe('2027-04');
});

it('tells the installment a year from now when prepayments lower it', function (): void {
    $planner = new LoanPlanner(new LoanCalculator);
    $lower = $planner->plan(3_200_000, 9.9, 72_600, 55, 50_000, 500_000, PrepayMode::ReduceInstallment, CarbonImmutable::parse('2026-11-01'), 450_000);
    $shorter = $planner->plan(3_200_000, 9.9, 72_600, 55, 50_000, 500_000, PrepayMode::ReduceTerm, CarbonImmutable::parse('2026-11-01'), 450_000);

    expect($lower->installmentInYear)->toBeLessThan($lower->installmentAfterFirst)
        ->and($shorter->installmentInYear)->toBe(72_600);
});
