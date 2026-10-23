<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * "Gyakori · egy koppintás": a category and amount recorded at least three times in the last
 * 30 days becomes a one-tap chip in the entry sheet.
 */
final class FrequentEntries
{
    public const int MIN_COUNT = 3;

    public const int DAYS = 30;

    public const int LIMIT = 6;

    /**
     * @return list<array{category_id: int, amount: int, count: int}> most used first
     */
    public function forUser(User $user, CarbonImmutable $today): array
    {
        $rows = $user->transactions()
            ->whereNull('pocket_id')
            ->whereDate('occurred_on', '>', $today->subDays(self::DAYS)->toDateString())
            ->whereDate('occurred_on', '<=', $today->toDateString())
            ->selectRaw('category_id, amount, count(*) as uses, max(occurred_on) as last_used')
            ->groupBy('category_id', 'amount')
            ->havingRaw('count(*) >= ?', [self::MIN_COUNT])
            ->orderByDesc('uses')
            ->orderByDesc('last_used')
            ->limit(self::LIMIT)
            ->toBase()
            ->get();

        $entries = [];

        foreach ($rows as $row) {
            if (is_numeric($row->category_id) && is_numeric($row->amount) && is_numeric($row->uses)) {
                $entries[] = ['category_id' => (int) $row->category_id, 'amount' => (int) $row->amount, 'count' => (int) $row->uses];
            }
        }

        return $entries;
    }
}
