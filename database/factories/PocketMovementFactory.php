<?php

namespace Database\Factories;

use App\Enums\PocketMovementType;
use App\Models\Pocket;
use App\Models\PocketMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PocketMovement>
 */
class PocketMovementFactory extends Factory
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
            'pocket_id' => Pocket::factory(),
            'amount' => 10_000,
            'type' => PocketMovementType::Deposit,
            'occurred_on' => now()->toDateString(),
        ];
    }
}
