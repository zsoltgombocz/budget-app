<?php

namespace App\Services;

use App\Models\Period;
use App\Models\User;
use App\Models\WatchedCategory;
use App\Notifications\BudgetAlert;
use Illuminate\Support\Facades\Cache;

/**
 * The 80% note on a watched category: on Today always, as a push only when the user turned it on,
 * and once per category per month.
 */
final readonly class BudgetAlerts
{
    /**
     * @var list<int>
     */
    public const array THRESHOLDS = [80];

    /**
     * The highest threshold (in percent) the spending crossed with this change, if any.
     */
    public function crossedThreshold(int $planned, int $spentBefore, int $spentAfter): ?int
    {
        if ($planned <= 0) {
            return null;
        }

        $crossed = null;

        foreach (self::THRESHOLDS as $threshold) {
            $limit = $planned * $threshold / 100;

            if ($spentBefore < $limit && $spentAfter >= $limit) {
                $crossed = $threshold;
            }
        }

        return $crossed;
    }

    /**
     * Spending paid from a goal does not count.
     */
    public function afterSpending(User $user, Period $period, int $categoryId, int $amount): void
    {
        if (! $user->settings()->watch_push_enabled || ! $user->pushSubscriptions()->exists()) {
            return;
        }

        $watch = $user->visibleWatchedCategories()->firstWhere('category_id', $categoryId);

        if (! $watch instanceof WatchedCategory) {
            return;
        }

        $spentAfter = (int) $period->transactions()->where('category_id', $categoryId)->whereNull('pocket_id')->sum('amount');
        $threshold = $this->crossedThreshold($watch->amount, $spentAfter - $amount, $spentAfter);

        if ($threshold !== null && Cache::add("notification:watch:{$user->id}:{$categoryId}:{$period->id}", true, now()->addDays(40))) {
            $user->notify(new BudgetAlert($categoryId, $watch->category->name ?? '', $threshold, $watch->amount - $spentAfter));
        }
    }
}
