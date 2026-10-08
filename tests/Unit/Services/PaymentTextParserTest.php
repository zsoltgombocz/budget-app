<?php

use App\Enums\Currency;
use App\Enums\PaymentKind;
use App\Services\Data\ParsedPayment;
use App\Services\PaymentTextParser;

/*
 * Sample notification texts. None of them is a verified, real bank notification: they are
 * ASSUMPTIONS modelled on the typical shape of each bank's SMS / push format (amount with
 * a currency sign, merchant after a separator or a label, balance at the end). Replace
 * them with real, anonymised texts as soon as users send some in.
 */

function parsePayment(?string $amount = null, ?string $merchant = null, ?string $text = null, ?string $title = null, Currency $base = Currency::HUF): ParsedPayment
{
    return resolve(PaymentTextParser::class)->parse($base, amountField: $amount, merchantField: $merchant, text: $text, title: $title);
}

it('reads the Apple Wallet fields of the iOS Shortcut', function (string $amount, int $minor, string $currency): void {
    $parsed = parsePayment(amount: $amount, merchant: 'Tesco');

    expect($parsed->kind)->toBe(PaymentKind::Purchase)
        ->and($parsed->amount)->toBe($minor)
        ->and($parsed->currency)->toBe($currency)
        ->and($parsed->merchant)->toBe('Tesco');
})->with([
    // Assumption: Shortcuts turns the Wallet amount into text with the phone's locale.
    'Hungarian locale' => ['8 400 Ft', 8_400, 'HUF'],
    'Hungarian locale, no-break space' => ["8\u{00A0}400\u{00A0}Ft", 8_400, 'HUF'],
    'Hungarian locale with decimals' => ['8 400,00 Ft', 8_400, 'HUF'],
    'English locale' => ['HUF 8,400', 8_400, 'HUF'],
    'English locale, symbol' => ['Ft 8,400.00', 8_400, 'HUF'],
    'ISO code after, dotted thousands' => ['8.400,00 HUF', 8_400, 'HUF'],
    'no space before Ft' => ['8400Ft', 8_400, 'HUF'],
    'bare negative number' => ['-8400', 8_400, 'HUF'],
    'bare number with decimals' => ['8400.00', 8_400, 'HUF'],
    'fillér rounds to forint' => ['8 399,50 Ft', 8_400, 'HUF'],
]);

it('keeps foreign currency amounts in their own currency', function (string $amount, int $minor, string $currency): void {
    $parsed = parsePayment(amount: $amount, merchant: 'Spotify');

    expect($parsed->amount)->toBe($minor)
        ->and($parsed->currency)->toBe($currency)
        ->and($parsed->baseAmount)->toBeNull();
})->with([
    'euro sign before' => ['€12.99', 1_299, 'EUR'],
    'euro sign after, comma' => ['12,99 €', 1_299, 'EUR'],
    'dollar' => ['$4.50', 450, 'USD'],
    'pound' => ['£1,234.56', 123_456, 'GBP'],
    'Czech crown' => ['249 Kč', 24_900, 'CZK'],
    'unsupported but known code' => ['99,00 SEK', 9_900, 'SEK'],
]);

it('uses the base currency amount when the account currency is euro', function (): void {
    $parsed = parsePayment(amount: '12,99 €', merchant: 'Spotify', base: Currency::EUR);

    expect($parsed->baseAmount)->toBe(1_299);
});

it('reads bank notification texts', function (string $text, ?string $title, PaymentKind $kind, ?int $amount, ?string $currency, ?int $baseAmount, ?string $merchant): void {
    $parsed = parsePayment(text: $text, title: $title);

    expect($parsed->kind)->toBe($kind)
        ->and($parsed->amount)->toBe($amount)
        ->and($parsed->currency)->toBe($currency)
        ->and($parsed->baseAmount)->toBe($baseAmount)
        ->and($parsed->merchant)->toBe($merchant);
})->with([
    // OTP Bank – assumed SMS-like push format.
    'OTP purchase' => ['OTPdirekt - Vásárlás: 2026.10.08 12:34:56 -8 400 HUF; TESCO ARUHAZ BUDAPEST; Kártyaszám: ...1234; Egyenleg: +123 456 HUF', 'OTP Bank', PaymentKind::Purchase, 8_400, 'HUF', 8_400, 'TESCO ARUHAZ BUDAPEST'],
    'OTP foreign purchase with forint amount' => ['OTPdirekt - Vásárlás: 2026.10.08 18:02:11 -12,99 EUR (-5 132 HUF); SPOTIFY AB STOCKHOLM; Kártyaszám: ...1234; Egyenleg: +118 324 HUF', 'OTP Bank', PaymentKind::Purchase, 1_299, 'EUR', 5_132, 'SPOTIFY AB STOCKHOLM'],
    'OTP refund' => ['OTPdirekt - Jóváírás (visszatérítés): 2026.10.09 09:12:03 +3 200 HUF; TESCO ARUHAZ BUDAPEST; Egyenleg: +126 456 HUF', 'OTP Bank', PaymentKind::Refund, 3_200, 'HUF', 3_200, 'TESCO ARUHAZ BUDAPEST'],
    'OTP salary' => ['OTPdirekt - Átutalás jóváírás: +609 000 HUF; Közlemény: munkabér; Egyenleg: +735 456 HUF', 'OTP Bank', PaymentKind::Income, 609_000, 'HUF', 609_000, null],
    // K&H – assumed.
    'K&H purchase' => ['K&H mobilbank: kártyás vásárlás 8 400 Ft, TESCO, 2026.10.08 12:34. Egyenleg: 123 456 Ft', 'K&H mobilbank', PaymentKind::Purchase, 8_400, 'HUF', 8_400, 'TESCO'],
    'K&H cash withdrawal' => ['K&H mobilbank: ATM készpénzfelvétel 20 000 Ft, K&H ATM BUDAPEST', 'K&H mobilbank', PaymentKind::Withdrawal, 20_000, 'HUF', 20_000, 'K&H ATM BUDAPEST'],
    'K&H outgoing transfer' => ['K&H mobilbank: átutalás terhelés 150 000 Ft, Kedvezményezett: Kovács Anna', 'K&H mobilbank', PaymentKind::Transfer, 150_000, 'HUF', 150_000, 'Kovács Anna'],
    // Erste (George) – assumed.
    'Erste purchase' => ['Kártyás fizetés: -8.400,00 HUF, TESCO ARUHAZ BUDAPEST', 'George', PaymentKind::Purchase, 8_400, 'HUF', 8_400, 'TESCO ARUHAZ BUDAPEST'],
    'Erste declined' => ['Elutasított kártyás fizetés: 8.400,00 HUF, TESCO ARUHAZ', 'George', PaymentKind::Declined, 8_400, 'HUF', 8_400, 'TESCO ARUHAZ'],
    // MBH – assumed.
    'MBH purchase with label' => ['Sikeres kártyás vásárlás: 8 400 HUF, Hely: TESCO ARUHAZ, Kártya: *1234', 'MBH Bank', PaymentKind::Purchase, 8_400, 'HUF', 8_400, 'TESCO ARUHAZ'],
    'MBH direct debit' => ['Csoportos beszedés: 12 990 HUF, Kedvezményezett: Telekom', 'MBH Bank', PaymentKind::Transfer, 12_990, 'HUF', 12_990, 'Telekom'],
    // Revolut – assumed.
    'Revolut English' => ['Paid €12.99 at Spotify', 'Revolut', PaymentKind::Purchase, 1_299, 'EUR', null, 'Spotify'],
    'Revolut Hungarian, merchant in the title' => ['Fizettél 8 400 Ft-ot', 'Tesco', PaymentKind::Purchase, 8_400, 'HUF', 8_400, 'Tesco'],
    'Revolut refund' => ['Refund of €12.99 from Amazon', 'Revolut', PaymentKind::Refund, 1_299, 'EUR', null, 'Amazon'],
    'Revolut top-up' => ['Top-up of 50 000 Ft was successful', 'Revolut', PaymentKind::Income, 50_000, 'HUF', 50_000, null],
    // Google Wallet – assumed: merchant as the title, amount and card in the text.
    'Google Wallet' => ['8 400 Ft · Visa •••• 1234', 'Tesco', PaymentKind::Purchase, 8_400, 'HUF', 8_400, 'Tesco'],
    'Google Wallet English' => ['€12.99 with Mastercard ••1234', 'Spotify', PaymentKind::Purchase, 1_299, 'EUR', null, 'Spotify'],
    // Wise – assumed.
    'Wise' => ['You spent 12.99 EUR at Spotify.', 'Wise', PaymentKind::Purchase, 1_299, 'EUR', null, 'Spotify'],
    // Not payments.
    'balance only' => ['Egyenleg: 123 456 HUF', 'OTP Bank', PaymentKind::Purchase, null, null, null, null],
    'marketing' => ['Új ajánlat vár a számládhoz!', 'OTP Bank', PaymentKind::Purchase, null, null, null, null],
]);

it('does not read card numbers, dates or bank names as amounts or currencies', function (): void {
    $parsed = parsePayment(text: 'OTP 2026.10.08 12:34 Kártya *1234 vásárlás 1 500 Ft; LIDL');

    expect($parsed->amount)->toBe(1_500)
        ->and($parsed->currency)->toBe('HUF')
        ->and($parsed->merchant)->toBe('LIDL');
});

it('normalises currency signs and codes', function (string $token, ?string $iso): void {
    expect(resolve(PaymentTextParser::class)->currency($token))->toBe($iso);
})->with([
    ['Ft', 'HUF'], ['huf', 'HUF'], ['€', 'EUR'], ['zł', 'PLN'], ['lei', 'RON'], ['OTP', null], ['ATM', null],
]);

it('converts numbers to the smallest unit', function (string $number, string $currency, int $minor): void {
    expect(resolve(PaymentTextParser::class)->toMinor($number, $currency))->toBe($minor);
})->with([
    ['8 400', 'HUF', 8_400],
    ['8.400,00', 'HUF', 8_400],
    ['8,400', 'HUF', 8_400],
    ['8 400,-', 'HUF', 8_400],
    ['12,99', 'EUR', 1_299],
    ['1,234.56', 'USD', 123_456],
    ['12.5', 'EUR', 1_250],
]);
