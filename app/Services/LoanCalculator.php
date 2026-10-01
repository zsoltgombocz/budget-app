<?php

namespace App\Services;

use App\Enums\PrepayMode;
use App\Services\Data\LoanState;
use InvalidArgumentException;

final class LoanCalculator
{
    /**
     * Annuity installment: A = P · r(1+r)^n / ((1+r)^n − 1), r = THM / 12.
     *
     * @param  float  $thm  annual percentage rate in percent, e.g. 12.5
     */
    public function annuity(int $principal, float $thm, int $months): int
    {
        if ($months <= 0) {
            throw new InvalidArgumentException('The term must be at least one month.');
        }

        if ($principal <= 0) {
            return 0;
        }

        $rate = $this->monthlyRate($thm);

        if ($rate <= 0.0) {
            return (int) ceil($principal / $months);
        }

        $growth = (1 + $rate) ** $months;

        return (int) round($principal * $rate * $growth / ($growth - 1));
    }

    /**
     * Number of months needed to repay the principal with a fixed installment.
     */
    public function termFor(int $principal, float $thm, int $installment): int
    {
        if ($principal <= 0) {
            return 0;
        }

        if ($installment <= 0) {
            throw new InvalidArgumentException('The installment must be positive.');
        }

        $rate = $this->monthlyRate($thm);

        if ($rate <= 0.0) {
            return (int) ceil($principal / $installment);
        }

        $ratio = 1 - $rate * $principal / $installment;

        if ($ratio <= 0) {
            throw new InvalidArgumentException('The installment does not cover the monthly interest.');
        }

        return (int) ceil(-log($ratio) / log(1 + $rate));
    }

    /**
     * Loan state after a prepayment. Installments exclude insurance.
     *
     * Without THM or remaining term the new installment (or term) is estimated
     * proportionally to the principal.
     */
    public function afterPrepayment(
        LoanState $loan,
        int $prepayment,
        PrepayMode $mode,
        ?float $thm,
    ): LoanState {
        if ($prepayment <= 0) {
            throw new InvalidArgumentException('The prepayment must be positive.');
        }

        $principal = max(0, $loan->principal - $prepayment);

        if ($principal === 0) {
            return new LoanState(principal: 0, installment: 0, remainingMonths: 0);
        }

        $hasTerms = $thm !== null && $loan->remainingMonths !== null && $loan->remainingMonths > 0;

        if ($mode === PrepayMode::ReduceInstallment) {
            $installment = $hasTerms
                ? $this->annuity($principal, (float) $thm, (int) $loan->remainingMonths)
                : $this->proportional($loan->installment, $principal, $loan->principal);

            return new LoanState($principal, $installment, $loan->remainingMonths);
        }

        $months = $hasTerms
            ? $this->termFor($principal, (float) $thm, $loan->installment)
            : ($loan->remainingMonths === null ? null : (int) ceil($loan->remainingMonths * $principal / max(1, $loan->principal)));

        return new LoanState($principal, $loan->installment, $months);
    }

    private function proportional(int $value, int $newPrincipal, int $oldPrincipal): int
    {
        if ($oldPrincipal <= 0) {
            return 0;
        }

        return (int) round($value * $newPrincipal / $oldPrincipal);
    }

    private function monthlyRate(float $thm): float
    {
        return $thm / 100 / 12;
    }
}
