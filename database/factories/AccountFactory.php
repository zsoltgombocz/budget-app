<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Enums\Currency;
use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
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
            'name' => fake()->company(),
            'type' => AccountType::Bank,
            'currency' => Currency::HUF,
        ];
    }
}
