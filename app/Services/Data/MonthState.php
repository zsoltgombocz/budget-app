<?php

namespace App\Services\Data;

use App\Enums\Pace;

/**
 * The numbers the Today and Month screens show for one month, all in the base currency's smallest unit.
 */
final readonly class MonthState
{
    public function __construct(
        /** Income + extra income + carried over − every fixed line (savings lines included). */
        public int $spendable,
        /** Spent this month, today included. */
        public int $spent,
        /** "Még erre a hónapra": spendable − spent; negative once the month is overspent. */
        public int $remaining,
        /** Days left in the month, today included. */
        public int $daysLeft,
        /** Today's daily allowance: what was left this morning ÷ days left; spending today does not change it. */
        public int $dailyAllowance,
        public int $spentToday,
        /** Today's allowance minus today's spending; negative when today is over. */
        public int $todayLeft,
        /** Tomorrow's allowance if nothing else is spent today; null on the last day. */
        public ?int $tomorrowAllowance,
        public Pace $pace,
    ) {}

    public function isOverToday(): bool
    {
        return $this->todayLeft < 0;
    }

    public function overToday(): int
    {
        return max(0, -$this->todayLeft);
    }
}
