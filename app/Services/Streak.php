<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The streak: days in a row on which something was recorded or "Ma nem költöttem" was marked.
 * Today still counts as open until the day ends, so a streak that reached yesterday is still alive.
 */
final class Streak
{
    /**
     * @param  list<string>  $activeDates  Y-m-d dates with an entry or a no-spend mark
     */
    public function current(array $activeDates, CarbonImmutable $today): int
    {
        $active = array_flip($activeDates);
        $day = isset($active[$today->toDateString()]) ? $today : $today->subDay();
        $count = 0;

        while (isset($active[$day->toDateString()])) {
            $count++;
            $day = $day->subDay();
        }

        return $count;
    }

    public function forUser(User $user, CarbonImmutable $today): int
    {
        $since = $today->subYear()->toDateString();
        $dates = [];

        foreach ([...$user->transactions()->whereDate('occurred_on', '>=', $since)->pluck('occurred_on'), ...$user->dayMarks()->whereDate('date', '>=', $since)->pluck('date')] as $date) {
            if ($date instanceof CarbonImmutable) {
                $dates[$date->toDateString()] = true;
            }
        }

        return $this->current(array_keys($dates), $today);
    }
}
