<?php

use App\Services\CaptureDeduplicator;
use App\Services\MerchantCategorizer;

it('builds the same key for spellings of one shop', function (string $merchant, string $key): void {
    expect(resolve(MerchantCategorizer::class)->key($merchant))->toBe($key);
})->with([
    ['Tesco', 'tesco'],
    ['TESCO ARUHAZ BUDAPEST 41012', 'tesco aruhaz'],
    ['Tesco Áruház', 'tesco aruhaz'],
    ['SUMUP *KAVEZO 12', 'kavezo'],
    ['MOL Nyrt. 0123 Budaörs', 'mol budaors'],
    ['Spotify AB', 'spotify'],
    ['1234', ''],
]);

it('tells whether two merchant names can be the same shop', function (?string $first, ?string $second, bool $same): void {
    expect(resolve(CaptureDeduplicator::class)->merchantsMatch($first, $second))->toBe($same);
})->with([
    'same name' => ['Tesco', 'TESCO', true],
    'bank adds the branch' => ['Tesco', 'TESCO ARUHAZ BUDAPEST', true],
    'one is missing' => [null, 'Tesco', true],
    'different shops' => ['Tesco', 'Lidl', false],
]);
