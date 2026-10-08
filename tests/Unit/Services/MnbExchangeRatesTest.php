<?php

use App\Enums\Currency;
use App\Services\ExchangeRates\ExchangeRatesUnavailable;
use App\Services\ExchangeRates\MnbExchangeRates;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('reads the current rates from the MNB SOAP service', function (): void {
    Http::fake(['www.mnb.hu/*' => Http::response(mnbSoapResponse(['EUR' => [1, '366,45'], 'JPY' => [100, '206,94'], 'USD' => [1, '327,48']]))]);

    $rates = new MnbExchangeRates('http://www.mnb.hu/arfolyamok.asmx')->latest();

    expect($rates->date)->toBe('2026-10-08')
        ->and($rates->hufPer(Currency::EUR))->toBe('366.45000000')
        ->and($rates->hufPer(Currency::USD))->toBe('327.48000000')
        ->and($rates->hufPerUnit['JPY'])->toBe('2.06940000')
        ->and($rates->hufPer(Currency::HUF))->toBe('1');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://www.mnb.hu/arfolyamok.asmx'
        && $request->header('SOAPAction')[0] === '"http://www.mnb.hu/webservices/MNBArfolyamServiceSoap/GetCurrentExchangeRates"'
        && str_contains($request->body(), '<web:GetCurrentExchangeRates/>'));
});

it('says when a currency has no rate', function (): void {
    Http::fake(['*' => Http::response(mnbSoapResponse(['EUR' => [1, '366,45']]))]);

    new MnbExchangeRates('http://www.mnb.hu/arfolyamok.asmx')->latest()->hufPer(Currency::PLN);
})->throws(ExchangeRatesUnavailable::class);

it('reports MNB as unavailable on an error or an unusable answer', function (int $status, string $body): void {
    Http::fake(['*' => Http::response($body, $status)]);

    new MnbExchangeRates('http://www.mnb.hu/arfolyamok.asmx')->latest();
})->with([
    'server error' => [503, 'Service Unavailable'],
    'not xml' => [200, '<html>maintenance</html'],
    'no rates' => [200, mnbSoapResponse([])],
])->throws(ExchangeRatesUnavailable::class);

it('reports MNB as unavailable when it cannot be reached', function (): void {
    Http::fake(['*' => fn () => throw new ConnectionException('timed out')]);

    new MnbExchangeRates('http://www.mnb.hu/arfolyamok.asmx')->latest();
})->throws(ExchangeRatesUnavailable::class);
