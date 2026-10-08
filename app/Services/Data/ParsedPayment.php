<?php

namespace App\Services\Data;

use App\Enums\PaymentKind;

/**
 * What the parser read from a payment notification.
 */
final readonly class ParsedPayment
{
    /**
     * @param  int|null  $amount  Absolute amount paid, in the smallest unit of `currency`.
     * @param  string|null  $currency  ISO code of what was paid.
     * @param  int|null  $baseAmount  Absolute amount in the user's base currency, when the text has it (or it is the paid currency).
     */
    public function __construct(
        public PaymentKind $kind,
        public ?int $amount,
        public ?string $currency,
        public ?int $baseAmount,
        public ?string $merchant,
    ) {}

    public function hasAmount(): bool
    {
        return $this->amount !== null && $this->amount > 0;
    }
}
