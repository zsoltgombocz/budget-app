<?php

namespace Database\Factories;

use App\Enums\CalcMode;
use App\Models\BudgetLine;
use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetLine>
 */
class BudgetLineFactory extends Factory
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
            'amount' => 10_000,
            'amount_avg' => null,
            'amount_max' => null,
            'calc_mode' => CalcMode::Fixed,
            'due_day' => null,
        ];
    }
}
