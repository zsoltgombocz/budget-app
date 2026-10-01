<?php

namespace Database\Factories;

use App\Models\Pocket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pocket>
 */
class PocketFactory extends Factory
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
            'name' => fake()->word(),
            'balance' => 0,
            'target_amount' => null,
            'prepay_step' => null,
            'is_reserve' => false,
            'is_shared' => false,
        ];
    }
}
