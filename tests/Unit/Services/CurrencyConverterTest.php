<?php

use App\Enums\Currency;
use App\Services\CurrencyConverter;

it('converts forints to euro cents at the MNB rate', function (): void {
    // 150 000 Ft / 393.45 = 381.2428… €
    expect(new CurrencyConverter()->convert(150_000, Currency::HUF, Currency::EUR, '1', '393.45'))->toBe(38_124);
});

it('converts euro cents to forints', function (): void {
    expect(new CurrencyConverter()->convert(38_124, Currency::EUR, Currency::HUF, '393.45', '1'))->toBe(149_999);
});

it('converts between two foreign currencies via the forint', function (): void {
    // 100.00 € = 36 645 Ft = 111.90 $ at 327.48 Ft/$
    expect(new CurrencyConverter()->convert(10_000, Currency::EUR, Currency::USD, '366.45', '327.48'))->toBe(11_190);
});

it('rounds half away from zero, also for negative amounts', function (): void {
    $converter = new CurrencyConverter;

    // 1 Ft / 400 = 0.25 cent; 2 Ft = 0.5 cent; 6 Ft = 1.5 cents.
    expect($converter->convert(2, Currency::HUF, Currency::EUR, '1', '400'))->toBe(1)
        ->and($converter->convert(-2, Currency::HUF, Currency::EUR, '1', '400'))->toBe(-1)
        ->and($converter->convert(1, Currency::HUF, Currency::EUR, '1', '400'))->toBe(0)
        ->and($converter->convert(-6, Currency::HUF, Currency::EUR, '1', '400'))->toBe(-2);
});

it('keeps amounts in the same currency as they are', function (): void {
    expect(new CurrencyConverter()->convert(12_345, Currency::EUR, Currency::EUR, '400', '400'))->toBe(12_345);
});

it('refuses a missing or non-positive rate', function (string $rate): void {
    new CurrencyConverter()->convert(100, Currency::HUF, Currency::EUR, '1', $rate);
})->with(['0', '-3', 'abc'])->throws(InvalidArgumentException::class);
