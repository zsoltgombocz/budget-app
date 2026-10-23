<?php

use App\Services\Streak;
use Carbon\CarbonImmutable;

it('counts the days in a row up to today', function (): void {
    expect(new Streak()->current(['2026-10-13', '2026-10-14', '2026-10-15'], CarbonImmutable::parse('2026-10-15')))->toBe(3);
});

it('keeps a streak that reached yesterday alive today', function (): void {
    expect(new Streak()->current(['2026-10-13', '2026-10-14'], CarbonImmutable::parse('2026-10-15')))->toBe(2);
});

it('starts over after a missed day', function (): void {
    expect(new Streak()->current(['2026-10-11', '2026-10-13'], CarbonImmutable::parse('2026-10-15')))->toBe(0)
        ->and(new Streak()->current([], CarbonImmutable::parse('2026-10-15')))->toBe(0);
});
