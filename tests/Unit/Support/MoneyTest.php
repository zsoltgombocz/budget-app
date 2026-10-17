<?php

use App\Enums\Currency;
use App\Support\Money;

it('formats a negative amount with a real minus sign, never a hyphen', function (): void {
    $formatted = Money::of(-27_539, Currency::HUF)->format('hu');

    expect($formatted)->toStartWith("\u{2212}")
        ->not->toContain('-')
        ->and(Money::of(27_539, Currency::HUF)->format('hu'))->not->toContain("\u{2212}");
});
