<?php

use App\Enums\PrepayMode;
use App\Services\Data\LoanState;
use App\Services\LoanCalculator;

it('computes the annuity installment', function (int $principal, float $thm, int $months, int $installment): void {
    expect(new LoanCalculator()->annuity($principal, $thm, $months))->toBe($installment);
})->with([
    '4M at 12% for 60 months' => [4_000_000, 12.0, 60, 88_978],
    '3.5M at 12% for 60 months' => [3_500_000, 12.0, 60, 77_856],
    '2M at 9.9% for 36 months' => [2_000_000, 9.9, 36, 64_441],
    'interest free' => [1_200_000, 0.0, 12, 100_000],
]);

it('computes the remaining term for a fixed installment', function (): void {
    expect(new LoanCalculator()->termFor(3_500_000, 12.0, 88_978))->toBe(51)
        ->and(new LoanCalculator()->termFor(1_000_000, 0.0, 300_000))->toBe(4);
});

it('rejects an installment that does not cover the interest', function (): void {
    new LoanCalculator()->termFor(10_000_000, 12.0, 50_000);
})->throws(InvalidArgumentException::class);

it('lowers the installment after a prepayment using the annuity formula', function (): void {
    $after = new LoanCalculator()->afterPrepayment(
        new LoanState(principal: 4_000_000, installment: 88_978, remainingMonths: 60),
        500_000,
        PrepayMode::ReduceInstallment,
        thm: 12.0,
    );

    expect($after->principal)->toBe(3_500_000)
        ->and($after->installment)->toBe(77_856)
        ->and($after->remainingMonths)->toBe(60);
});

it('estimates the installment proportionally without THM', function (): void {
    $after = new LoanCalculator()->afterPrepayment(
        new LoanState(principal: 4_000_000, installment: 85_000, remainingMonths: null),
        500_000,
        PrepayMode::ReduceInstallment,
        thm: null,
    );

    expect($after->installment)->toBe(74_375);
});

it('shortens the term when reducing the term', function (): void {
    $after = new LoanCalculator()->afterPrepayment(
        new LoanState(principal: 4_000_000, installment: 88_978, remainingMonths: 60),
        500_000,
        PrepayMode::ReduceTerm,
        thm: 12.0,
    );

    expect($after->installment)->toBe(88_978)
        ->and($after->remainingMonths)->toBe(51);
});

it('closes the loan when the prepayment covers the principal', function (): void {
    $after = new LoanCalculator()->afterPrepayment(
        new LoanState(principal: 300_000, installment: 30_000, remainingMonths: 10),
        500_000,
        PrepayMode::ReduceInstallment,
        thm: 10.0,
    );

    expect($after->principal)->toBe(0)
        ->and($after->installment)->toBe(0)
        ->and($after->remainingMonths)->toBe(0);
});
