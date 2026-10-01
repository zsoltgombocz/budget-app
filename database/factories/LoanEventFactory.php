<?php

namespace Database\Factories;

use App\Enums\LoanEventType;
use App\Models\Loan;
use App\Models\LoanEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoanEvent>
 */
class LoanEventFactory extends Factory
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
            'loan_id' => Loan::factory(),
            'type' => LoanEventType::Payment,
            'amount' => 85_000,
            'principal_after' => 3_950_000,
            'installment_after' => 85_000,
            'occurred_on' => now()->toDateString(),
        ];
    }
}
