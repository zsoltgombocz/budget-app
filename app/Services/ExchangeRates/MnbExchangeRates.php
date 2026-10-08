<?php

namespace App\Services\ExchangeRates;

use DOMDocument;
use DOMElement;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Official rates of the Magyar Nemzeti Bank from its SOAP web service
 * (GetCurrentExchangeRates), called with a raw envelope because the image has no ext-soap.
 *
 * The answer wraps an XML document like
 * <MNBCurrentExchangeRates><Day date="2026-10-08"><Rate unit="1" curr="EUR">366,45</Rate>…
 * where each rate is the forint price of "unit" units (100 for JPY, for example).
 */
final readonly class MnbExchangeRates implements ExchangeRateSource
{
    private const string SOAP_ACTION = 'http://www.mnb.hu/webservices/MNBArfolyamServiceSoap/GetCurrentExchangeRates';

    private const string ENVELOPE = '<?xml version="1.0" encoding="utf-8"?>'
        .'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:web="http://www.mnb.hu/webservices/">'
        .'<soap:Body><web:GetCurrentExchangeRates/></soap:Body></soap:Envelope>';

    public function __construct(private string $url, private int $timeoutSeconds = 10) {}

    public function latest(): ExchangeRates
    {
        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withHeaders(['SOAPAction' => '"'.self::SOAP_ACTION.'"'])
                ->withBody(self::ENVELOPE, 'text/xml; charset=utf-8')
                ->post($this->url)
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw new ExchangeRatesUnavailable('The MNB exchange rate service could not be reached.', $exception->getCode(), previous: $exception);
        }

        return $this->parse($response->body());
    }

    /**
     * @throws ExchangeRatesUnavailable
     */
    public function parse(string $soapResponse): ExchangeRates
    {
        $result = $this->load($soapResponse)?->getElementsByTagName('GetCurrentExchangeRatesResult')->item(0)?->textContent;
        $day = $result === null ? null : $this->load($result)?->getElementsByTagName('Day')->item(0);

        if (! $day instanceof DOMElement || preg_match('/^\d{4}-\d{2}-\d{2}$/', $day->getAttribute('date')) !== 1) {
            throw new ExchangeRatesUnavailable('The MNB answer has no exchange rates.');
        }

        $rates = [];

        foreach ($day->getElementsByTagName('Rate') as $rate) {
            $code = $rate->getAttribute('curr');
            $unit = $rate->getAttribute('unit') === '' ? '1' : $rate->getAttribute('unit');
            $value = str_replace(',', '.', trim($rate->textContent));

            if (preg_match('/^[A-Z]{3}$/', $code) !== 1 || ! is_numeric($value) || (float) $value <= 0 || preg_match('/^[1-9]\d*$/', $unit) !== 1) {
                continue;
            }

            $rates[$code] = bcdiv($value, $unit, 8);
        }

        if ($rates === []) {
            throw new ExchangeRatesUnavailable('The MNB answer has no exchange rates.');
        }

        return new ExchangeRates($day->getAttribute('date'), $rates);
    }

    private function load(string $xml): ?DOMDocument
    {
        if (trim($xml) === '') {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }
}
