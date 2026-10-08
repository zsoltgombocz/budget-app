<?php

namespace App\Services\Data;

use App\Models\PaymentCapture;

final readonly class CaptureResult
{
    /**
     * @param  bool  $repeated  The payment had already arrived; nothing new was stored.
     */
    public function __construct(
        public PaymentCapture $capture,
        public bool $repeated = false,
    ) {}
}
