<?php

namespace App\Services;

use App\Enums\DueState;
use App\Services\Data\MonthLine;
use Carbon\CarbonImmutable;

/**
 * The state of each fixed line on a day, and the "6-ból 5 levonva / elintézve" count.
 *
 * Without bank data "levonva" is an assumption that holds once the day has passed. Only lines paid
 * by hand (and savings) ever ask for something: an "Elintéztem" / "Félretettem" tap, which changes
 * no number.
 */
final class FixedLines
{
    /**
     * @param  list<MonthLine>  $lines
     * @return list<MonthLine> the same lines with their state, open ones first by due date
     */
    public function states(array $lines, CarbonImmutable $today): array
    {
        $states = array_map(fn (MonthLine $line): MonthLine => $line->withState($this->state($line, $today)), $lines);

        usort($states, fn (MonthLine $a, MonthLine $b): int => [$this->rank($a), $a->dueOn->timestamp ?? PHP_INT_MAX, $a->name]
            <=> [$this->rank($b), $b->dueOn->timestamp ?? PHP_INT_MAX, $b->name]);

        return $states;
    }

    public function state(MonthLine $line, CarbonImmutable $today): DueState
    {
        if ($line->skipped) {
            return DueState::Skipped;
        }

        if (! $line->dueOn instanceof CarbonImmutable) {
            return $line->manualPay && $line->done ? DueState::Done : DueState::NoDay;
        }

        $day = $line->dueOn->startOfDay();
        $today = $today->startOfDay();

        if ($line->manualPay) {
            return match (true) {
                $line->done => DueState::Done,
                $day->equalTo($today) => DueState::ToDo,
                $day->lessThan($today) => DueState::Overdue,
                default => DueState::Upcoming,
            };
        }

        return match (true) {
            $day->equalTo($today) => DueState::DueToday,
            $day->lessThan($today) => DueState::Deducted,
            default => DueState::Upcoming,
        };
    }

    /**
     * "6-ból 5": lines with a day this month (not skipped) and how many of them have gone out.
     *
     * @param  list<MonthLine>  $lines  lines with their state (see states())
     * @return array{withDay: int, settled: int, noDay: int}
     */
    public function summary(array $lines): array
    {
        $withDay = 0;
        $settled = 0;
        $noDay = 0;

        foreach ($lines as $line) {
            match ($line->state) {
                DueState::NoDay => $noDay++,
                DueState::Skipped, null => null,
                default => $withDay++,
            };

            if ($line->state?->isSettled() === true && $line->dueOn instanceof CarbonImmutable) {
                $settled++;
            }
        }

        return ['withDay' => $withDay, 'settled' => $settled, 'noDay' => $noDay];
    }

    private function rank(MonthLine $line): int
    {
        return match ($line->state) {
            DueState::Overdue, DueState::ToDo, DueState::DueToday => 0,
            DueState::Upcoming => 1,
            DueState::Done, DueState::Deducted => 2,
            DueState::NoDay => 3,
            default => 4,
        };
    }
}
