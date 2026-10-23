<?php

namespace App\Services;

use App\Services\Data\WatchState;

/**
 * Watched categories: only a look at a category's spending, never part of the month's money.
 */
final class Watching
{
    public const int ALERT_PERCENT = 80;

    /** How many categories the base plan watches; Plusz watches any number. */
    public const int BASE_LIMIT = 2;

    /**
     * @param  array<int, array{name: string, amount: int}>  $watched  category id => name and monthly amount
     * @param  array<int, int>  $spentByCategory  category id => spent this month
     * @return list<WatchState> in the order given
     */
    public function states(array $watched, array $spentByCategory): array
    {
        $states = [];

        foreach ($watched as $categoryId => $watch) {
            $states[] = new WatchState($categoryId, $watch['name'], $watch['amount'], $spentByCategory[$categoryId] ?? 0);
        }

        return $states;
    }

    /**
     * Whether one more category can be watched on the given plan.
     */
    public function canWatchMore(int $watchedCount, bool $hasPlusz): bool
    {
        return $hasPlusz || $watchedCount < self::BASE_LIMIT;
    }
}
