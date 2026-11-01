<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountValue;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountValue>
 */
class AccountValueFactory extends Factory
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
            'account_id' => Account::factory(),
            'date' => now()->toDateString(),
            'value' => fake()->numberBetween(100_000, 10_000_000),
        ];
    }
}
