<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Models\CurrencyConversion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CurrencyConversion>
 */
class CurrencyConversionFactory extends Factory
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
            'from_currency' => Currency::HUF,
            'to_currency' => Currency::EUR,
            'from_rate' => '1',
            'to_rate' => '400',
            'rate_date' => now()->toDateString(),
        ];
    }
}
