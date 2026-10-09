<?php

namespace App\Services\Data;

use App\Enums\Currency;

/**
 * What a base currency change will do, shown before the user confirms it.
 *
 * Either a conversion at today's MNB rate ($restores false), or undoing earlier conversions
 * so the original amounts come back ($restores true, at the rates used back then).
 */
final readonly class CurrencyChangePreview
{
    /**
     * @param  string  $hufPerFrom  forints per one unit of $from (for a conversion)
     * @param  string  $hufPerTo  forints per one unit of $to (for a conversion)
     * @param  string  $rateDate  the MNB day of the rates (for a conversion)
     * @param  list<array{date: string, currency: string, huf_per: string}>  $rates  the rates to show
     * @param  int  $exampleBefore  an example amount in $from (the monthly income)
     * @param  int  $exampleAfter  the same amount after the change, in $to
     * @param  int  $linesWithOriginal  plan lines with an original amount in $to, which take that amount
     */
    public function __construct(
        public Currency $from,
        public Currency $to,
        public bool $restores,
        public string $hufPerFrom,
        public string $hufPerTo,
        public string $rateDate,
        public array $rates,
        public int $exampleBefore,
        public int $exampleAfter,
        public int $linesWithOriginal,
    ) {}

    /**
     * @return array{from: string, to: string, restores: bool, huf_per_from: string, huf_per_to: string, rate_date: string, rates: list<array{date: string, currency: string, huf_per: string}>, example_before: int, example_after: int, lines_with_original: int}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->value,
            'to' => $this->to->value,
            'restores' => $this->restores,
            'huf_per_from' => $this->hufPerFrom,
            'huf_per_to' => $this->hufPerTo,
            'rate_date' => $this->rateDate,
            'rates' => $this->rates,
            'example_before' => $this->exampleBefore,
            'example_after' => $this->exampleAfter,
            'lines_with_original' => $this->linesWithOriginal,
        ];
    }

    /**
     * @param  array{from: string, to: string, restores: bool, huf_per_from: string, huf_per_to: string, rate_date: string, rates: list<array{date: string, currency: string, huf_per: string}>, example_before: int, example_after: int, lines_with_original: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            from: Currency::from($data['from']),
            to: Currency::from($data['to']),
            restores: $data['restores'],
            hufPerFrom: $data['huf_per_from'],
            hufPerTo: $data['huf_per_to'],
            rateDate: $data['rate_date'],
            rates: $data['rates'],
            exampleBefore: $data['example_before'],
            exampleAfter: $data['example_after'],
            linesWithOriginal: $data['lines_with_original'],
        );
    }
}
