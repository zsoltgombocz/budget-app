<?php

namespace App\Enums;

/**
 * What a payment notification is about, as read from its text.
 */
enum PaymentKind: string
{
    /** A card or phone payment: the only kind that becomes a spending. */
    case Purchase = 'purchase';
    case Refund = 'refund';
    /** Money coming in (salary, transfer received, top-up). */
    case Income = 'income';
    /** Outgoing bank transfer or direct debit; these are usually fixed plan items. */
    case Transfer = 'transfer';
    case Withdrawal = 'withdrawal';
    case Declined = 'declined';

    /**
     * Whether this kind never becomes a spending on its own.
     */
    public function isIgnored(): bool
    {
        return in_array($this, [self::Income, self::Transfer, self::Withdrawal, self::Declined], true);
    }
}
