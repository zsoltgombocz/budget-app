<?php

use App\Support\Dates;
use Carbon\CarbonImmutable;

it('writes "from this day" with the Hungarian suffix that fits the spoken day', function (int $day, string $expected): void {
    app()->setLocale('hu');

    expect(Dates::from(CarbonImmutable::create(2026, 10, $day)))->toBe($expected);
})->with([
    'egytől' => [1, 'okt 1-től'],
    'háromtól' => [3, 'okt 3-tól'],
    'öttől' => [5, 'okt 5-től'],
    'hattól' => [6, 'okt 6-tól'],
    'nyolctól' => [8, 'okt 8-tól'],
    'tíztől' => [10, 'okt 10-től'],
    'tizenháromtól' => [13, 'okt 13-tól'],
    'húsztól' => [20, 'okt 20-tól'],
    'huszonegytől' => [21, 'okt 21-től'],
    'huszonhattól' => [26, 'okt 26-tól'],
    'harminctól' => [30, 'okt 30-tól'],
    'harmincegytől' => [31, 'okt 31-től'],
]);

it('writes "from this day" in English', function (): void {
    app()->setLocale('en');

    expect(Dates::from(CarbonImmutable::create(2026, 10, 10)))->toBe('from Oct 10');
});

it('puts a Hungarian month name in a case in its vowel harmony', function (): void {
    app()->setLocale('hu');

    expect(Dates::monthCase(CarbonImmutable::parse('2026-11-01'), 'in'))->toBe('novemberben')
        ->and(Dates::monthCase(CarbonImmutable::parse('2026-11-01'), 'into'))->toBe('novemberbe')
        ->and(Dates::monthCase(CarbonImmutable::parse('2027-01-01'), 'from'))->toBe('januárból')
        ->and(Dates::monthCase(CarbonImmutable::parse('2027-04-01'), 'in'))->toBe('áprilisban');
});
