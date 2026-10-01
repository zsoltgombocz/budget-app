<?php

namespace Database\Factories;

use App\Enums\TransactionSource;
use App\Models\Category;
use App\Models\Period;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
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
            'category_id' => Category::factory(),
            'amount' => fake()->numberBetween(500, 20_000),
            'occurred_on' => now()->toDateString(),
            'note' => null,
            'source' => TransactionSource::Manual,
            'client_uuid' => fake()->uuid(),
        ];
    }
}
