<?php

namespace App\Services;

use App\Services\Data\Allocation;
use InvalidArgumentException;

final class AllocationCalculator
{
    /**
     * Split a period's leftover.
     *
     * Negative leftover is covered from the reserve pocket (never from an overdraft);
     * whatever the reserve cannot cover is reported as uncovered.
     * Positive leftover goes to the reserve first: min(leftover × pct, room to target),
     * the rest to the user's surplus target (investment account or pocket).
     *
     * @param  bool  $hasReserve  whether the user has a reserve pocket at all
     * @param  int|null  $reserveTarget  null means the reserve has no cap
     */
    public function allocate(int $leftover, int $reservePct, bool $hasReserve, int $reserveBalance = 0, ?int $reserveTarget = null): Allocation
    {
        if ($reservePct < 0 || $reservePct > 100) {
            throw new InvalidArgumentException('Reserve percentage must be between 0 and 100.');
        }

        if ($leftover < 0) {
            $deficit = -$leftover;
            $fromReserve = $hasReserve ? min($deficit, max(0, $reserveBalance)) : 0;

            return new Allocation(
                leftover: $leftover,
                toReserve: 0,
                toSurplus: 0,
                fromReserve: $fromReserve,
                uncovered: $deficit - $fromReserve,
            );
        }

        $toReserve = 0;

        if ($hasReserve) {
            $share = intdiv($leftover * $reservePct, 100);
            $room = $reserveTarget === null ? $share : max(0, $reserveTarget - $reserveBalance);
            $toReserve = min($share, $room);
        }

        return new Allocation(
            leftover: $leftover,
            toReserve: $toReserve,
            toSurplus: $leftover - $toReserve,
            fromReserve: 0,
            uncovered: 0,
        );
    }
}
