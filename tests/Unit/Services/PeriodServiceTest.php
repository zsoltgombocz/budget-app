<?php

use App\Enums\PeriodMode;
use App\Services\PeriodService;
use Carbon\CarbonImmutable;

it('computes period bounds', function (PeriodMode $mode, ?int $payday, string $date, string $start, string $end): void {
    [$from, $to] = resolve(PeriodService::class)->boundsFor($mode, $payday, CarbonImmutable::parse($date));

    expect($from->toDateString())->toBe($start)
        ->and($to->toDateString())->toBe($end);
})->with([
    'calendar month' => [PeriodMode::Calendar, null, '2026-02-15', '2026-02-01', '2026-02-28'],
    'payday before payday in month' => [PeriodMode::Payday, 10, '2026-10-01', '2026-09-10', '2026-10-09'],
    'payday on payday' => [PeriodMode::Payday, 10, '2026-10-10', '2026-10-10', '2026-11-09'],
    'payday 31 clamps to short months' => [PeriodMode::Payday, 31, '2026-11-15', '2026-10-31', '2026-11-29'],
    'payday 31 on the last day' => [PeriodMode::Payday, 31, '2026-10-31', '2026-10-31', '2026-11-29'],
    'payday 30 in February' => [PeriodMode::Payday, 30, '2027-02-28', '2027-02-28', '2027-03-29'],
    'payday across the year' => [PeriodMode::Payday, 5, '2027-01-03', '2026-12-05', '2027-01-04'],
    'payday without day falls back to calendar' => [PeriodMode::Payday, null, '2026-10-20', '2026-10-01', '2026-10-31'],
]);
