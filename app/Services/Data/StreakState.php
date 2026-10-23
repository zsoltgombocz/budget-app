<?php

namespace App\Services\Data;

final readonly class StreakState
{
    public function __construct(
        public int $current,
        public int $longest,
        /** Yesterday was missed and the rest day kept the streak ("Tegnap pihentünk, a sorozat megmaradt"). */
        public bool $restUsedYesterday,
        /** A missed day now would be a rest day ("1 pihenőnapod van"). */
        public bool $restAvailable,
    ) {}
}
