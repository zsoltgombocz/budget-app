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

/**
 * A GetCurrentExchangeRates answer as MNB sends it: the rates are an escaped XML document.
 *
 * @param  array<string, array{0: int, 1: string}>  $rates  code => [unit, value with a decimal comma]
 */
function mnbSoapResponse(array $rates, string $date = '2026-10-08'): string
{
    $inner = '<MNBCurrentExchangeRates><Day date="'.$date.'">';

    foreach ($rates as $code => [$unit, $value]) {
        $inner .= '<Rate unit="'.$unit.'" curr="'.$code.'">'.$value.'</Rate>';
    }

    $inner .= '</Day></MNBCurrentExchangeRates>';

    return '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
        .'<GetCurrentExchangeRatesResponse xmlns="http://www.mnb.hu/webservices/" xmlns:i="http://www.w3.org/2001/XMLSchema-instance">'
        .'<GetCurrentExchangeRatesResult>'.htmlspecialchars($inner, ENT_XML1).'</GetCurrentExchangeRatesResult>'
        .'</GetCurrentExchangeRatesResponse></s:Body></s:Envelope>';
}
