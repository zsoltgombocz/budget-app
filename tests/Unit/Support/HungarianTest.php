<?php

use App\Support\Hungarian;

it('puts the Hungarian definite article that fits the first sound', function (string $word, string $expected): void {
    expect(Hungarian::withArticle($word))->toBe($expected);
})->with([
    'vowel' => ['anna@example.com', 'az anna@example.com'],
    'accented vowel' => ['ödön@example.com', 'az ödön@example.com'],
    'capital vowel' => ['Eszter@example.com', 'az Eszter@example.com'],
    'consonant' => ['peter@example.com', 'a peter@example.com'],
    'five' => ['5os@example.com', 'az 5os@example.com'],
]);

it('puts a case suffix after a number in the harmony it is read with', function (int $number, string $expected): void {
    expect(Hungarian::withSuffix($number, 'ból', 'ből'))->toBe($expected);
})->with([
    [1, '1-ből'], [2, '2-ből'], [3, '3-ból'], [5, '5-ből'], [6, '6-ból'], [8, '8-ból'], [9, '9-ből'],
    [10, '10-ből'], [20, '20-ból'], [30, '30-ból'], [40, '40-ből'], [60, '60-ból'], [100, '100-ból'], [1000, '1000-ből'], [13, '13-ból'],
]);
