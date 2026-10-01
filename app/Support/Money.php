<?php

namespace App\Support;

use App\Enums\Currency;
use InvalidArgumentException;
use JsonSerializable;
use NumberFormatter;
use Stringable;

/**
 * Immutable money amount stored as an integer in the currency's smallest unit.
 */
final readonly class Money implements JsonSerializable, Stringable
{
    public function __construct(
        public int $amount,
        public Currency $currency,
    ) {}

    public static function of(int $amount, Currency|string $currency): self
    {
        return new self($amount, $currency instanceof Currency ? $currency : Currency::from($currency));
    }

    /**
     * Build from a whole major-unit value as typed on the numpad (no decimals).
     */
    public static function fromMajor(int $major, Currency $currency): self
    {
        return new self($major * $currency->minorPerMajor(), $currency);
    }

    /**
     * Parse user input like "24,99", "24.99" or "609 000" into the smallest unit.
     * Returns null when the input is not a valid non-negative amount.
     */
    public static function parse(string $input, Currency $currency): ?int
    {
        $normalized = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', trim($input)) ?? '';
        $normalized = str_replace(',', '.', $normalized);

        if ($normalized === '' || preg_match('/^\d+(\.\d+)?$/', $normalized) !== 1) {
            return null;
        }

        [$major, $minor] = array_pad(explode('.', $normalized, 2), 2, '');

        if (strlen($minor) > $currency->decimals()) {
            return null;
        }

        return (int) $major * $currency->minorPerMajor() + (int) str_pad($minor, $currency->decimals(), '0');
    }

    /**
     * Plain number for an input field, e.g. 2499 cents → "24.99", 609000 HUF → "609000".
     */
    public static function toInput(?int $amount, Currency $currency): string
    {
        if ($amount === null) {
            return '';
        }

        if ($currency->decimals() === 0) {
            return (string) $amount;
        }

        return number_format($amount / $currency->minorPerMajor(), $currency->decimals(), '.', '');
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function format(?string $locale = null): string
    {
        $formatter = new NumberFormatter($locale ?? app()->getLocale(), NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $this->currency->decimals());

        $formatted = $formatter->formatCurrency($this->amount / $this->currency->minorPerMajor(), $this->currency->value);

        // ICU uses narrow no-break spaces as group separators; normalise to a plain no-break space.
        return str_replace("\u{202F}", "\u{00A0}", (string) $formatted);
    }

    public function __toString(): string
    {
        return $this->format();
    }

    /**
     * @return array{amount: int, currency: string}
     */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency->value];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Currency mismatch: {$this->currency->value} vs {$other->currency->value}.");
        }
    }
}
