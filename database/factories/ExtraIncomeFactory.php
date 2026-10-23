<?php

namespace Database\Factories;

use App\Models\ExtraIncome;
use App\Models\Period;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExtraIncome>
 */
class ExtraIncomeFactory extends Factory
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
            'amount' => fake()->numberBetween(1, 30) * 1_000,
            'occurred_on' => now()->toDateString(),
        ];
    }
}
