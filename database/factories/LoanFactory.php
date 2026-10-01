<?php

namespace Database\Factories;

use App\Enums\PrepayMode;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Loan>
 */
class LoanFactory extends Factory
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
            'name' => 'Personal loan',
            'lender' => null,
            'principal_balance' => 4_000_000,
            'installment' => 85_000,
            'insurance' => 2_549,
            'thm' => 12.5,
            'remaining_months' => 60,
            'prepay_mode' => PrepayMode::ReduceInstallment,
        ];
    }
}
