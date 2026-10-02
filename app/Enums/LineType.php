<?php

namespace App\Enums;

enum LineType: string
{
    case Transfer = 'transfer';
    case Loan = 'loan';
    case Fixed = 'fixed';
    case Variable = 'variable';
    case Sinking = 'sinking';

    public function label(): string
    {
        return match ($this) {
            self::Transfer => __('Transfers'),
            self::Loan => __('Loan repayments'),
            self::Fixed => __('Fixed costs'),
            self::Variable => __('Variable budgets'),
            self::Sinking => __('Sinking funds'),
        };
    }

    /**
     * Whether spending in this type has to be recorded by the user,
     * as opposed to counting as fulfilled by the plan itself.
     */
    public function isTracked(): bool
    {
        return $this === self::Variable;
    }
}
