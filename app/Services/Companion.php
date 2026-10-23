<?php

namespace App\Services;

use App\Services\Data\CompanionLine;
use App\Services\Data\MonthView;
use App\Services\Data\WatchState;

/**
 * Picks Morzsa's mood and sentence for the Today screen. One thing at a time, the most useful first;
 * never scolding, never pushing to spend, and every number in it is the screen's own number.
 */
final class Companion
{
    /**
     * @param  list<WatchState>  $watched
     */
    public function forToday(MonthView $view, array $watched, int $streak): CompanionLine
    {
        $state = $view->state;

        if ($state->remaining <= 0 && $state->spent > 0) {
            return new CompanionLine('elgondolkodo', __('This month’s money is used up. You can still record, I’ll keep count.'));
        }

        if ($state->isOverToday()) {
            return new CompanionLine('elgondolkodo', __('Noted. Good that you wrote it down.'));
        }

        foreach ($watched as $watch) {
            if ($watch->isNearing()) {
                return new CompanionLine('kivancsi', __(':category: :percent% of what you set is gone, :left left.', [
                    'percent' => $watch->percent(),
                    'category' => $watch->name,
                    'left' => money($watch->left()),
                ]));
            }
        }

        if ($view->noSpendToday) {
            return new CompanionLine('vidam', __('A quiet day. The streak goes on.'));
        }

        if ($state->spentToday === 0) {
            return new CompanionLine('nyugodt', $streak > 1
                ? __(':count days in a row. Today’s allowance is :amount.', ['count' => $streak, 'amount' => money($state->dailyAllowance)])
                : __('Good morning. Today’s allowance is :amount.', ['amount' => money($state->dailyAllowance)]));
        }

        return new CompanionLine('nyugodt');
    }
}
