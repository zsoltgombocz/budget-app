<?php

use App\Services\Streak;
use Carbon\CarbonImmutable;

/**
 * @return list<string>
 */
function days(string ...$days): array
{
    return array_map(fn (string $day): string => '2026-10-'.$day, $days);
}

it('counts the days in a row up to today', function (): void {
    expect(new Streak()->current(days('13', '14', '15'), CarbonImmutable::parse('2026-10-15')))->toBe(3);
});

it('keeps a streak that reached yesterday alive today', function (): void {
    expect(new Streak()->current(days('13', '14'), CarbonImmutable::parse('2026-10-15')))->toBe(2);
});

it('bridges one missed day as a rest day and says so', function (): void {
    $state = new Streak()->state(days('10', '11', '12', '14', '15'), CarbonImmutable::parse('2026-10-15'));

    expect($state->current)->toBe(5)
        ->and($state->restAvailable)->toBeFalse();
});

it('says a rest day was used yesterday', function (): void {
    $state = new Streak()->state(days('11', '12', '13'), CarbonImmutable::parse('2026-10-15'));

    expect($state->current)->toBe(3)
        ->and($state->restUsedYesterday)->toBeTrue();
});

it('ends the streak at a second miss within seven days', function (): void {
    expect(new Streak()->current(days('08', '09', '11', '13', '14'), CarbonImmutable::parse('2026-10-14')))->toBe(3);
});

it('allows a rest day again after seven days', function (): void {
    expect(new Streak()->current(days('01', '02', '04', '05', '06', '07', '08', '09', '10', '11', '13'), CarbonImmutable::parse('2026-10-13')))->toBe(11);
});

it('starts over after two missed days in a row', function (): void {
    expect(new Streak()->current(days('10'), CarbonImmutable::parse('2026-10-13')))->toBe(0)
        ->and(new Streak()->current([], CarbonImmutable::parse('2026-10-15')))->toBe(0);
});

it('keeps the longest streak', function (): void {
    $state = new Streak()->state(days('01', '02', '03', '04', '05', '09', '10'), CarbonImmutable::parse('2026-10-10'));

    expect($state->current)->toBe(2)
        ->and($state->longest)->toBe(5)
        ->and($state->restAvailable)->toBeTrue();
});
