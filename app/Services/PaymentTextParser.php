<?php

namespace App\Services;

use App\Enums\Currency;
use App\Enums\PaymentKind;
use App\Services\Data\ParsedPayment;
use Illuminate\Support\Str;

/**
 * Reads amount, currency, merchant and kind from what a phone sends in: the Apple Wallet
 * fields of an iOS Shortcut ("8 400 Ft", "Tesco") or the raw text of an Android bank
 * notification. Lives on the server so new bank formats need no app release.
 */
final class PaymentTextParser
{
    /**
     * Currency signs and words mapped to ISO codes (matched case-sensitively, longest first).
     *
     * @var array<string, string>
     */
    private const array SYMBOLS = [
        'US$' => 'USD',
        'Ft.' => 'HUF',
        'Ft' => 'HUF',
        'ft' => 'HUF',
        '€' => 'EUR',
        '$' => 'USD',
        '£' => 'GBP',
        'Kč' => 'CZK',
        'zł' => 'PLN',
        'lei' => 'RON',
    ];

    /**
     * ISO codes recognised next to a number. Kept explicit so words like "OTP" or "ATM" never read as a currency.
     *
     * @var list<string>
     */
    private const array CODES = ['HUF', 'EUR', 'USD', 'GBP', 'CHF', 'CZK', 'PLN', 'RON', 'SEK', 'NOK', 'DKK', 'JPY', 'CAD', 'AUD', 'TRY', 'BGN', 'RSD', 'UAH', 'CNY', 'HRK'];

    /** Words before an amount that make it a balance, not the payment. */
    private const string BALANCE_WORDS = '/(egyenleg|elerheto|available|balance|disponibil|keret)[^0-9]{0,12}$/';

    /** Titles that name the bank or the notification, not the merchant. */
    private const string GENERIC_TITLE = '/bank|otp|k&h|erste|george|mbh|revolut|wise|wallet|google pay|apple pay|mobil|fizetes|vasarlas|tranzakcio|payment|purchase|transaction|ertesites|notification|kartya|card|terheles/';

    /**
     * @param  string|null  $amountField  Amount as sent by the Shortcut, e.g. "8 400 Ft", "€12.99" or "-8400".
     * @param  string|null  $merchantField  Merchant as sent by the Shortcut.
     * @param  string|null  $text  Raw notification text (Android).
     * @param  string|null  $title  Notification title (Android); often the merchant in Google Wallet.
     * @param  string|null  $currencyField  Explicit currency for a bare amount.
     */
    public function parse(
        Currency $base,
        ?string $amountField = null,
        ?string $merchantField = null,
        ?string $text = null,
        ?string $title = null,
        ?string $currencyField = null,
    ): ParsedPayment {
        $amountField = $this->clean($amountField);
        $text = $this->clean($text);
        $title = $this->clean($title);

        $amounts = [];
        $source = '';

        foreach ([$amountField, $text, $title] as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $amounts = $this->amounts($candidate);

            if ($amounts !== []) {
                $source = $candidate;
                break;
            }
        }

        if ($amounts === [] && $amountField !== null) {
            $bare = $this->bareAmount($amountField, $this->currency($currencyField ?? '') ?? $base->value);

            if ($bare !== null) {
                $amounts = [$bare];
                $source = $amountField;
            }
        }

        $payments = array_values(array_filter($amounts, fn (array $found): bool => ! $found['balance']));
        $primary = $payments[0] ?? null;

        $baseAmount = null;

        if ($primary !== null) {
            $baseAmount = $primary['currency'] === $base->value
                ? $primary['amount']
                : collect($payments)->firstWhere('currency', $base->value)['amount'] ?? null;
        }

        $words = Str::lower(Str::ascii(implode("\n", array_filter([$title, $text, $amountField]))));
        $kind = $this->kind($words, $primary['sign'] ?? 0);

        $merchant = $this->cleanMerchant($merchantField)
            ?? ($text !== null ? $this->merchantFromText($text, $source === $text ? $this->amountsEnd($amounts, $primary) : null) : null)
            ?? $this->merchantFromTitle($title);

        return new ParsedPayment(
            kind: $kind,
            amount: $primary['amount'] ?? null,
            currency: $primary['currency'] ?? null,
            baseAmount: $baseAmount,
            merchant: $merchant,
        );
    }

    /**
     * Normalise a currency sign, word or code to its ISO code.
     */
    public function currency(string $token): ?string
    {
        $token = trim($token);

        if (isset(self::SYMBOLS[$token])) {
            return self::SYMBOLS[$token];
        }

        $upper = Str::upper($token);

        return in_array($upper, self::CODES, true) ? $upper : null;
    }

    /**
     * Turn a matched number into the smallest unit, e.g. "8.400,00" HUF → 8400, "12,99" EUR → 1299.
     */
    public function toMinor(string $number, string $currency): int
    {
        $number = (string) preg_replace('/[\s\x{00A0}\x{202F}\'’]|,-$/u', '', $number);
        $decimals = Currency::tryFrom($currency)?->decimals() ?? 2;

        $fraction = '';

        if (preg_match('/^(.*)[.,](\d{1,2})$/', $number, $parts) === 1) {
            $number = $parts[1];
            $fraction = $parts[2];
        }

        $major = (int) preg_replace('/\D/', '', $number);
        $fraction = str_pad($fraction, 2, '0');

        if ($decimals === 0) {
            return $major + ((int) $fraction >= 50 ? 1 : 0);
        }

        return $major * (10 ** $decimals) + (int) substr(str_pad($fraction, $decimals, '0'), 0, $decimals);
    }

    /**
     * Every amount with a currency in the text, in order.
     *
     * @return list<array{amount: int, currency: string, sign: int, start: int, end: int, balance: bool}>
     */
    private function amounts(string $text): array
    {
        $currencies = implode('|', array_map(fn (string $token): string => preg_quote($token, '/'), [...array_keys(self::SYMBOLS), ...self::CODES]));
        $space = '[\s\x{00A0}\x{202F}]?';
        $number = '(?<![\d.,])(?:\d{1,3}(?:[\s\x{00A0}\x{202F}.,\'’]\d{3})+|\d+)(?:[.,]\d{1,2})?(?![\d])(?:,-)?';
        $pattern = '/(?<s1>[-−+])?'.$space.'(?:(?<![\p{L}])(?<c1>'.$currencies.')'.$space.'(?<s2>[-−+])?'.$space.'(?<n1>'.$number.')|(?<n2>'.$number.')'.$space.'(?<c2>'.$currencies.')(?![\p{L}]))/u';

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL) === false) {
            return [];
        }

        $found = [];

        foreach ($matches as $match) {
            $currency = $this->currency((string) ($match['c1'][0] ?? $match['c2'][0]));
            $number = $match['n1'][0] ?? $match['n2'][0];

            if ($currency === null || $number === null) {
                continue;
            }

            $signText = $match['s1'][0] ?? $match['s2'][0];
            $start = (int) $match[0][1];
            $before = Str::lower(Str::ascii(substr($text, max(0, $start - 40), min(40, $start))));

            $found[] = [
                'amount' => $this->toMinor($number, $currency),
                'currency' => $currency,
                'sign' => match ($signText) {
                    '+' => 1,
                    '-', '−' => -1,
                    default => 0,
                },
                'start' => $start,
                'end' => $start + strlen((string) $match[0][0]),
                'balance' => preg_match(self::BALANCE_WORDS, $before) === 1,
            ];
        }

        return $found;
    }

    /**
     * A number without a currency sign, e.g. "-8400" or "12.99".
     *
     * @return array{amount: int, currency: string, sign: int, start: int, end: int, balance: bool}|null
     */
    private function bareAmount(string $value, string $currency): ?array
    {
        if (preg_match('/^(?<sign>[-−+])?[\s\x{00A0}]*(?<number>\d[\d\s\x{00A0}\x{202F}.,\'’]*)$/u', trim($value), $match) !== 1) {
            return null;
        }

        return [
            'amount' => $this->toMinor($match['number'], $currency),
            'currency' => $currency,
            'sign' => match ($match['sign']) {
                '+' => 1,
                '-', '−' => -1,
                default => 0,
            },
            'start' => 0,
            'end' => strlen($value),
            'balance' => false,
        ];
    }

    /**
     * Decide the kind from words in the notification; a leading plus sign means money in.
     */
    private function kind(string $words, int $sign): PaymentKind
    {
        $rules = [
            [PaymentKind::Declined, '/elutasit|sikertelen|visszautasit|nem sikerult|declined|failed|rejected/'],
            [PaymentKind::Refund, '/visszaterit|refund|storno|sztorno|returned/'],
            [PaymentKind::Withdrawal, '/keszpenz ?felvet|\batm\b|cash withdrawal|withdrawal/'],
            [PaymentKind::Income, '/jovairas|beerkezo|bejovo|erkezett|received|incoming|you got|kaptal|money added|feltoltes|top-?up|topped up/'],
            [PaymentKind::Transfer, '/atutalas|beszedes|transfer|utalas/'],
        ];

        foreach ($rules as [$kind, $pattern]) {
            if (preg_match($pattern, $words) === 1) {
                return $kind;
            }
        }

        return $sign > 0 ? PaymentKind::Income : PaymentKind::Purchase;
    }

    /**
     * Where the payment's amounts end, so "-12,99 EUR (-5 132 HUF)" counts as one.
     *
     * @param  list<array{amount: int, currency: string, sign: int, start: int, end: int, balance: bool}>  $amounts
     * @param  array{amount: int, currency: string, sign: int, start: int, end: int, balance: bool}|null  $primary
     */
    private function amountsEnd(array $amounts, ?array $primary): ?int
    {
        if ($primary === null) {
            return null;
        }

        $end = $primary['end'];

        foreach ($amounts as $amount) {
            if ($amount['start'] > $primary['start'] && $amount['start'] - $end <= 3) {
                $end = $amount['end'];
            }
        }

        return $end;
    }

    private function merchantFromText(string $text, ?int $amountEnd): ?string
    {
        $labelled = '/(?:Helyszín|Hely|Kereskedő|Elfogadóhely|Elfogadó|Kedvezményezett|Merchant|Partner|itt)\s*:\s*(?<m>[^;\n|,]+)/iu';

        if (preg_match($labelled, $text, $match) === 1 && ($merchant = $this->cleanMerchant($match['m'])) !== null) {
            return $merchant;
        }

        $english = '/\b(?:at|from)\s+(?<m>[^\n;,]+?)(?=\s+(?:on|with|using|via|for)\b|[.;,]\s|[.;,]?\s*(?:\n|$))/iu';

        if (preg_match($english, $text, $match) === 1 && ($merchant = $this->cleanMerchant($match['m'])) !== null) {
            return $merchant;
        }

        if ($amountEnd === null) {
            return null;
        }

        // "8 400 Ft-ot": the Hungarian case ending belongs to the amount.
        $after = (string) preg_replace(['/^-(?:ot|et|öt|at|t)\b/u', '/^[\s,;:·•\-–—|()]+/u'], '', substr($text, $amountEnd));
        $segment = preg_split('/[;\n|]|,\s|\s·\s|\.\s/u', $after)[0] ?? '';

        return $this->cleanMerchant($segment);
    }

    private function merchantFromTitle(?string $title): ?string
    {
        if ($title === null || preg_match(self::GENERIC_TITLE, Str::lower(Str::ascii($title))) === 1) {
            return null;
        }

        return $this->cleanMerchant($title);
    }

    /**
     * Trim card masks, dates and punctuation; reject anything that is not a name.
     */
    private function cleanMerchant(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) preg_replace([
            '/[*•x]{1,4}\s?\d{4}\b/u',
            '/\s+\d{4}[.\-\/]\d{1,2}[.\-\/]\d{1,2}.*$/u',
            '/\s+\d{1,2}:\d{2}.*$/u',
            '/\s+/u',
        ], ['', '', '', ' '], $value);
        $value = trim($value, " \t.,;:-–—·•|()\u{00A0}");

        $ascii = Str::lower(Str::ascii($value));

        if (preg_match_all('/\p{L}/u', $value) < 2
            || preg_match('/^\d{4}[.\-\/]/', $value) === 1
            || preg_match('/^(egyenleg|elerheto|kartya|card|visa|mastercard|maestro|datum|idopont|osszeg|terheles|sikeres|tranzakcio|balance|available|kozlemeny|was |is |with |using |via |on \d)/', $ascii) === 1) {
            return null;
        }

        return Str::limit($value, 120, '');
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(Str::limit($value, 2000, ''));

        return $value === '' ? null : $value;
    }
}
