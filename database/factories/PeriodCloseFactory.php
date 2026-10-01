<?php

namespace Database\Factories;

use App\Models\Period;
use App\Models\PeriodClose;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PeriodClose>
 */
class PeriodCloseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'period_id' => Period::factory(),
            'planned_total' => 500_000,
            'actual_total' => 480_000,
            'leftover' => 120_000,
            'to_reserve' => 60_000,
            'to_invest' => 60_000,
            'from_reserve' => 0,
            'breakdown' => [],
        ];
    }
}
