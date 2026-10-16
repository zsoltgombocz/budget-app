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
