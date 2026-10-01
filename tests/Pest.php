<?php

use App\Enums\PeriodMode;
use App\Models\BudgetLine;
use App\Models\BudgetSetting;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Browser');

pest()->extend(TestCase::class)
    ->in('Unit');

/**
 * A user who finished onboarding with a small HUF plan:
 * rent 200 000 (fixed), fuel 60 000 (variable), groceries 90 000 (variable).
 *
 * @param  array<string, mixed>  $settings
 */
function onboardedUser(array $settings = []): User
{
    $user = User::factory()->create();

    BudgetSetting::factory()->for($user)->create([
        'period_mode' => PeriodMode::Calendar,
        'locale' => 'en',
        'income' => 500_000,
        ...$settings,
    ]);

    foreach ([['Rent', 'fixed', 200_000], ['Fuel', 'variable', 60_000], ['Groceries', 'variable', 90_000]] as $sort => [$name, $type, $amount]) {
        $category = Category::factory()->for($user)->create([
            'name' => $name,
            'type' => $type,
            'sort' => $sort,
            'is_quick_entry' => $type === 'variable',
        ]);

        BudgetLine::factory()->for($user)->for($category)->create(['amount' => $amount]);
    }

    return $user->refresh();
}
