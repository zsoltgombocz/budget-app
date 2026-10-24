<?php

namespace App\Services;

use App\Models\User;
use App\Services\Data\StreakState;
use Carbon\CarbonImmutable;

/**
 * The streak: days in a row on which something was recorded or "Ma nem költöttem" was marked.
 *
 * Today still counts as open until the day ends, so a streak that reached yesterday is alive.
 * One missed day in any seven is a rest day ("pihenőnap"): it does not count, but the streak goes on.
 * A second miss within seven days ends it. The longest streak is kept for good.
 */
final class Streak
{
    public const int REST_EVERY = 7;

    /**
     * @param  list<string>  $activeDates  Y-m-d dates with an entry or a no-spend mark
     */
    public function current(array $activeDates, CarbonImmutable $today): int
    {
        return $this->state($activeDates, $today)->current;
    }

    /**
     * @param  list<string>  $activeDates
     */
    public function state(array $activeDates, CarbonImmutable $today): StreakState
    {
        $active = array_flip($activeDates);
        $today = $today->startOfDay();

        return new StreakState(
            current: $this->runEndingAt($active, isset($active[$today->toDateString()]) ? $today : $today->subDay())[0],
            longest: $this->longest($activeDates, $today),
            restUsedYesterday: ! isset($active[$today->subDay()->toDateString()]) && isset($active[$today->subDays(2)->toDateString()])
                && $this->runEndingAt($active, $today->subDays(2))[0] > 0,
            restAvailable: $this->restAvailable($active, $today),
        );
    }

    public function forUser(User $user, CarbonImmutable $today): int
    {
        return $this->current($this->activeDates($user, $today), $today);
    }

    public function stateForUser(User $user, CarbonImmutable $today): StreakState
    {
        return $this->state($this->activeDates($user, $today), $today);
    }

    /**
     * @return list<string>
     */
    public function activeDates(User $user, CarbonImmutable $today, int $days = 400): array
    {
        $since = $today->subDays($days)->toDateString();
        $dates = [];

        foreach ([...$user->transactions()->whereDate('occurred_on', '>=', $since)->pluck('occurred_on'), ...$user->dayMarks()->whereDate('date', '>=', $since)->pluck('date')] as $date) {
            if ($date instanceof CarbonImmutable) {
                $dates[$date->toDateString()] = true;
            }
        }

        $dates = array_keys($dates);
        sort($dates);

        return $dates;
    }

    /**
     * The streak ending on $day, walking back with one rest day per seven days.
     *
     * @param  array<string, int>  $active
     * @return array{0: int, 1: int|null} days counted, and how many days back the last rest day was (null: none used)
     */
    private function runEndingAt(array $active, CarbonImmutable $day): array
    {
        $count = 0;
        $sinceRest = null;
        $step = 0;

        while (true) {
            if (isset($active[$day->toDateString()])) {
                $count++;
            } elseif (($count > 0 || $step === 0) && ($sinceRest === null || $step - $sinceRest >= self::REST_EVERY) && isset($active[$day->subDay()->toDateString()])) {
                $sinceRest = $step;
            } else {
                break;
            }

            $day = $day->subDay();
            $step++;
        }

        return [$count, $sinceRest];
    }

    /**
     * Whether a missed day today (or tomorrow) would still be a rest day.
     *
     * @param  array<string, int>  $active
     */
    private function restAvailable(array $active, CarbonImmutable $today): bool
    {
        $end = isset($active[$today->toDateString()]) ? $today : $today->subDay();
        [$count, $sinceRest] = $this->runEndingAt($active, $end);

        return $count > 0 && ($sinceRest === null || $sinceRest + 1 >= self::REST_EVERY);
    }

    /**
     * @param  list<string>  $activeDates
     */
    private function longest(array $activeDates, CarbonImmutable $today): int
    {
        $active = array_flip($activeDates);
        $longest = 0;

        foreach ($activeDates as $date) {
            $day = CarbonImmutable::parse($date);

            // Only the last day of a run needs checking: the next day is neither active nor a rest day.
            if (! isset($active[$day->addDay()->toDateString()])) {
                $longest = max($longest, $this->runEndingAt($active, $day)[0]);
            }
        }

        return max($longest, $this->runEndingAt($active, isset($active[$today->toDateString()]) ? $today : $today->subDay())[0]);
    }
}
