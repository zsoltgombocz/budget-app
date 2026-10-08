<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantRule>
 */
class MerchantRuleFactory extends Factory
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
            'merchant_key' => fake()->unique()->word(),
            'category_id' => Category::factory(),
        ];
    }
}
