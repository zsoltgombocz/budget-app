<?php

namespace App\Services\Data;

/**
 * The month on the Today card as SVG coordinates: what is left for the month day by day (solid),
 * the expected end (dashed), and the plan as a line.
 */
final readonly class Curve
{
    public const int WIDTH = 326;

    public const int HEIGHT = 120;

    /**
     * @param  list<array{0: float, 1: float}>  $points  one per elapsed day, from the period start to today
     */
    public function __construct(
        public array $points,
        public float $endX,
        public float $endY,
        public float $planY,
    ) {}

    public function line(): string
    {
        return implode(' ', array_map(
            fn (array $point, int $index): string => ($index === 0 ? 'M' : 'L').$this->format($point[0]).','.$this->format($point[1]),
            $this->points,
            array_keys($this->points),
        ));
    }

    public function area(): string
    {
        $last = $this->today();

        return $this->line().' L'.$this->format($last[0]).','.self::HEIGHT.' L0,'.self::HEIGHT.' Z';
    }

    /**
     * @return array{0: float, 1: float}
     */
    public function today(): array
    {
        return $this->points[array_key_last($this->points)] ?? [0.0, 0.0];
    }

    private function format(float $value): string
    {
        return number_format($value, 1, '.', '');
    }
}
