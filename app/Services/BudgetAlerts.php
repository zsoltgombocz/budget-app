<?php

namespace App\Services;

use App\Enums\LineType;
use App\Models\Period;
use App\Models\User;
use App\Notifications\BudgetAlert;

final readonly class BudgetAlerts
{
    /**
     * @var list<int>
     */
    public const array THRESHOLDS = [80, 100];

    public function __construct(private PlanService $plans) {}

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
     * Notify the user when a new spending pushed a variable budget over a threshold.
     */
    public function afterSpending(User $user, Period $period, int $categoryId, int $amount): void
    {
        if (! $user->pushSubscriptions()->exists()) {
            return;
        }

        $planned = 0;
        $name = '';

        foreach ($this->plans->linesFor($period) as $line) {
            if ($line->categoryId === $categoryId && $line->type === LineType::Variable) {
                $planned += $line->planned();
                $name = $line->categoryName;
            }
        }

        $spentAfter = (int) $period->transactions()->where('category_id', $categoryId)->sum('amount');
        $threshold = $this->crossedThreshold($planned, $spentAfter - $amount, $spentAfter);

        if ($threshold !== null) {
            $user->notify(new BudgetAlert($categoryId, $name, $threshold, $planned - $spentAfter));
        }
    }
}
