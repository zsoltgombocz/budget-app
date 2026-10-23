<?php

namespace App\Services\Data;

use App\Services\Watching;

/**
 * A watched category this month: what the user meant to spend on it and what went.
 */
final readonly class WatchState
{
    public function __construct(
        public int $categoryId,
        public string $name,
        public int $amount,
        public int $spent,
    ) {}

    public function left(): int
    {
        return $this->amount - $this->spent;
    }

    /**
     * Whole percent used, for the ring; can go above 100.
     */
    public function percent(): int
    {
        return $this->amount > 0 ? intdiv($this->spent * 100, $this->amount) : 0;
    }

    /**
     * 80% or more is gone, but not over yet: the one gentle note of the month.
     */
    public function isNearing(): bool
    {
        return ! $this->isOver() && $this->percent() >= Watching::ALERT_PERCENT;
    }

    public function isOver(): bool
    {
        return $this->spent > $this->amount;
    }
}
