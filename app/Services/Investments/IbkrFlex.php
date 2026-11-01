<?php

namespace App\Services\Investments;

use App\Enums\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RoundingMode;
use SimpleXMLElement;
use Throwable;

/**
 * Reads an Interactive Brokers account through the Flex Web Service: the user's read-only token
 * and a saved Activity Flex Query with the "Net Asset Value (NAV) in Base" section (XML).
 * The service makes the report first (SendRequest), then hands it over (GetStatement).
 */
final readonly class IbkrFlex
{
    /** IBKR's code for a report that is still being made. */
    private const string NOT_READY = '1019';

    public function __construct(
        private string $url,
        private int $attempts = 6,
        private int $waitSeconds = 5,
    ) {}

    /**
     * @throws IbkrFlexFailed
     */
    public function fetch(string $token, string $queryId): IbkrStatement
    {
        $request = $this->get('SendRequest', $token, $queryId);

        if ((string) $request->Status !== 'Success' || (string) $request->ReferenceCode === '') {
            throw new IbkrFlexFailed($this->message($request));
        }

        for ($attempt = 1; $attempt <= $this->attempts; $attempt++) {
            Sleep::for($this->waitSeconds)->seconds();
            $report = $this->get('GetStatement', $token, (string) $request->ReferenceCode);

            if ($report->getName() === 'FlexQueryResponse') {
                return $this->parse($report);
            }

            if ((string) $report->ErrorCode !== self::NOT_READY) {
                throw new IbkrFlexFailed($this->message($report));
            }
        }

        throw new IbkrFlexFailed(__('The report was not ready in time. It is tried again tomorrow, or tap Refresh.'));
    }

    /**
     * @throws IbkrFlexFailed
     */
    public function parse(SimpleXMLElement|string $report): IbkrStatement
    {
        $xml = $report instanceof SimpleXMLElement ? $report : $this->xml($report);
        $rows = $xml->xpath('//EquitySummaryByReportDateInBase') ?: [];

        if ($rows === []) {
            throw new IbkrFlexFailed(__('The Flex Query has no "Net Asset Value (NAV) in Base" section. Add it, and set the format to XML.'));
        }

        $code = (string) ($rows[0]['currency'] ?? '');

        if ($code === '') {
            $code = (string) (($xml->xpath('//AccountInformation')[0] ?? null)?->attributes()['currency'] ?? '');
        }

        $currency = Currency::tryFrom($code) ?? throw new IbkrFlexFailed(__('The account\'s base currency (:currency) is not supported.', ['currency' => $code !== '' ? $code : '?']));
        $values = [];

        foreach ($rows as $row) {
            $date = $this->date((string) ($row['reportDate'] ?? ''));
            $total = (string) ($row['total'] ?? '');

            if ($date !== null && is_numeric($total)) {
                $values[$date] = (int) bcround(bcmul($total, (string) $currency->minorPerMajor(), 4), 0, RoundingMode::HalfAwayFromZero);
            }
        }

        if ($values === []) {
            throw new IbkrFlexFailed(__('The report has no daily values. Set the Flex Query period to the last 365 days.'));
        }

        ksort($values);

        return new IbkrStatement($currency, $values);
    }

    /**
     * @throws IbkrFlexFailed
     */
    private function get(string $endpoint, string $token, string $query): SimpleXMLElement
    {
        try {
            $response = Http::timeout(30)->accept('application/xml')
                ->withUserAgent('MoneySight/'.config()->string('app.version'))
                ->get(rtrim($this->url, '/').'/'.$endpoint, ['t' => $token, 'q' => $query, 'v' => 3])
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            throw new IbkrFlexFailed(__('Interactive Brokers could not be reached.'), $exception->getCode(), previous: $exception);
        }

        return $this->xml($response->body());
    }

    /**
     * @throws IbkrFlexFailed
     */
    private function xml(string $body): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $xml instanceof SimpleXMLElement ? $xml : throw new IbkrFlexFailed(__('Interactive Brokers sent something unreadable.'));
    }

    private function message(SimpleXMLElement $response): string
    {
        $message = trim((string) $response->ErrorMessage);

        return $message !== '' ? __('Interactive Brokers: :message', ['message' => $message]) : __('Interactive Brokers refused the request.');
    }

    /**
     * Flex dates follow the query's date format: yyyyMMdd by default, or yyyy-MM-dd. The day-month
     * formats are ambiguous, so the setup asks for the default.
     */
    private function date(string $value): ?string
    {
        foreach (['Ymd', 'Y-m-d'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value);
            } catch (Throwable) {
                continue;
            }

            if ($date instanceof CarbonImmutable && $date->format($format) === $value) {
                return $date->toDateString();
            }
        }

        return null;
    }
}
