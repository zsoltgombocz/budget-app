<?php

namespace App\Services;

use App\Enums\PrepayMode;
use App\Services\Data\LoanPlan;
use Carbon\CarbonImmutable;

/**
 * Simulates a loan month by month: interest, the installment, and a monthly prepayment saving that is
 * prepaid in full each time it reaches the step. "Rövidebb futamidő" keeps the installment and ends
 * sooner; "Kisebb részlet" keeps the term and lowers the installment (LoanCalculator::annuity).
 */
final readonly class LoanPlanner
{
    private const int MAX_MONTHS = 600;

    public function __construct(private LoanCalculator $calculator) {}

    /**
     * @param  CarbonImmutable  $firstMonth  the month of the next installment
     * @param  int  $alreadySaved  what is set aside for the next prepayment already
     */
    public function plan(int $principal, float $thm, int $installment, int $remainingMonths, int $monthlySaving, int $step, PrepayMode $mode, CarbonImmutable $firstMonth, int $alreadySaved = 0): LoanPlan
    {
        [$monthsWithout, $interestWithout] = $this->run($principal, $thm, $installment, $remainingMonths, 0, $step, $mode, 0)[0];
        [[$monthsWith, $interestWith], $prepaidAt, $installmentAfter, $installmentInYear] = $this->run($principal, $thm, $installment, $remainingMonths, $monthlySaving, $step, $mode, $monthlySaving > 0 ? $alreadySaved : 0);
        $start = $firstMonth->startOfMonth();
        // The bank's months left are the truth without prepaying; the simulation only tells how much sooner.
        $endWithout = $start->addMonths(max(0, ($remainingMonths > 0 ? $remainingMonths : $monthsWithout) - 1));

        return new LoanPlan(
            endWithout: $endWithout,
            endWith: $endWithout->subMonths(max(0, $monthsWithout - $monthsWith)),
            interestWithout: $interestWithout,
            interestWith: $interestWith,
            prepayments: array_map(fn (int $month): CarbonImmutable => $start->addMonths($month - 1), $prepaidAt),
            installmentAfterFirst: $installmentAfter,
            installmentInYear: $installmentInYear,
        );
    }

    /**
     * @return array{0: array{0: int, 1: int}, 1: list<int>, 2: int|null, 3: int} [months, interest], prepayment months, installment after the first prepayment, installment after twelve months
     */
    private function run(int $principal, float $thm, int $installment, int $remainingMonths, int $monthlySaving, int $step, PrepayMode $mode, int $saved): array
    {
        $rate = $thm / 100 / 12;
        $balance = (float) $principal;
        $interest = 0.0;
        $month = 0;
        $prepaidAt = [];
        $installmentAfter = null;
        $left = max(1, $remainingMonths);
        $installmentInYear = $installment;

        while ($balance > 0.5 && $month < self::MAX_MONTHS) {
            $month++;
            $monthInterest = $balance * $rate;
            $interest += $monthInterest;
            $balance = max(0.0, $balance + $monthInterest - $installment);
            $left = max(1, $left - 1);

            if ($month === 12) {
                $installmentInYear = $balance > 0.5 ? $installment : 0;
            }

            if ($monthlySaving <= 0 || $balance <= 0.5) {
                continue;
            }

            $saved += $monthlySaving;

            if ($saved >= max(1, $step)) {
                $balance = max(0.0, $balance - $saved);
                $saved = 0;
                $prepaidAt[] = $month;

                if ($mode === PrepayMode::ReduceInstallment && $balance > 0.5) {
                    $installment = $this->calculator->annuity((int) round($balance), $thm, $left);
                    $installmentAfter ??= $installment;
                }
            }
        }

        return [[$month, (int) round($interest)], $prepaidAt, $installmentAfter, $month >= 12 ? $installmentInYear : 0];
    }
}
