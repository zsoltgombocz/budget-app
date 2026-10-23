<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\User;
use App\Models\WatchedCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WatchedCategory>
 */
class WatchedCategoryFactory extends Factory
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
            'category_id' => Category::factory(),
            'amount' => fake()->numberBetween(10, 60) * 1_000,
        ];
    }
}
