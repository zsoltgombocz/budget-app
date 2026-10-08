<?php

namespace App\Enums;

/**
 * Why a captured payment waits for the user or was skipped.
 */
enum CaptureReason: string
{
    /** Paid in another currency and the base-currency amount is unknown. */
    case ForeignCurrency = 'foreign_currency';
    /** A manual entry with the same amount already exists that day. */
    case PossibleDuplicate = 'possible_duplicate';
    case Refund = 'refund';
    /** The payment's day belongs to an already closed period. */
    case PeriodClosed = 'period_closed';
    /** There is no variable category to put it in. */
    case NoCategory = 'no_category';
    /** No amount could be read from it. */
    case NoAmount = 'no_amount';
    /** Income, transfer, cash withdrawal or a declined payment. */
    case NotSpending = 'not_spending';

    public function label(): string
    {
        return match ($this) {
            self::ForeignCurrency => __('Paid in another currency: how much was it in your currency?'),
            self::PossibleDuplicate => __('You already recorded the same amount by hand that day.'),
            self::Refund => __('Money back from a merchant.'),
            self::PeriodClosed => __('Its day belongs to a closed month.'),
            self::NoCategory => __('There is no variable category to put it in.'),
            self::NoAmount => __('No amount could be read from it.'),
            self::NotSpending => __('Not a card payment (income, transfer, cash withdrawal or declined).'),
        };
    }
}
