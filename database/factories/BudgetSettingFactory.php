<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\PeriodMode;
use App\Models\BudgetSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetSetting>
 */
class BudgetSettingFactory extends Factory
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
            'period_mode' => PeriodMode::Calendar,
            'payday_day' => null,
            'income' => 600_000,
            'currency' => Currency::HUF,
            'locale' => 'hu',
            'timezone' => 'Europe/Budapest',
            'reminder_time' => '20:30',
            'reminder_enabled' => true,
            'reserve_pct' => 100,
            'onboarded_at' => now(),
        ];
    }
}
