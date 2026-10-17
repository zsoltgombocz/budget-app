<?php

namespace App\Services\Data;

/**
 * One variable category in the forecast. `removedFromPlan`: the category was deleted from the plan
 * but still has spending this period, so it counts without a budget and has no plan line to open.
 */
final readonly class CategoryForecast
{
    public const float WARNING_RATIO = 0.8;

    public function __construct(
        public int $categoryId,
        public string $categoryName,
        public int $planned,
        public int $spent,
        public int $expected,
        public bool $removedFromPlan = false,
    ) {}

    public function ratio(): float
    {
        if ($this->planned <= 0) {
            return $this->spent > 0 ? INF : 0.0;
        }

        return $this->spent / $this->planned;
    }

    public function remaining(): int
    {
        return $this->planned - $this->spent;
    }

    public function isOver(): bool
    {
        return $this->spent > 0 && $this->ratio() >= 1.0;
    }

    public function isWarning(): bool
    {
        return ! $this->isOver() && $this->ratio() >= self::WARNING_RATIO;
    }
}
