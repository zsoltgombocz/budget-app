<?php

namespace Database\Factories;

use App\Enums\PeriodStatus;
use App\Models\Period;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Period>
 */
class PeriodFactory extends Factory
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
            'starts_on' => now()->startOfMonth()->toDateString(),
            'ends_on' => now()->endOfMonth()->toDateString(),
            'income_planned' => 600_000,
            'income_actual' => null,
            'status' => PeriodStatus::Open,
            'plan_snapshot' => null,
        ];
    }
}
