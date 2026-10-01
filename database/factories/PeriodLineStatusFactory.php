<?php

namespace Database\Factories;

use App\Models\BudgetLine;
use App\Models\Period;
use App\Models\PeriodLineStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PeriodLineStatus>
 */
class PeriodLineStatusFactory extends Factory
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
            'budget_line_id' => BudgetLine::factory(),
            'amount_actual' => null,
            'paid_on' => now()->toDateString(),
        ];
    }
}
