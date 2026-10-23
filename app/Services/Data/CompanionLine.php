<?php

namespace App\Services\Data;

/**
 * What Morzsa does on the Today screen: a mood (x-morzsa) and, when there is something to say, one sentence.
 */
final readonly class CompanionLine
{
    public function __construct(
        public string $mood,
        public ?string $text = null,
    ) {}
}
