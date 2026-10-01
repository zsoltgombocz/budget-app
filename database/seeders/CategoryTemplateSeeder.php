<?php

namespace Database\Seeders;

use App\Enums\LineType;
use App\Models\CategoryTemplate;
use Illuminate\Database\Seeder;

class CategoryTemplateSeeder extends Seeder
{
    /**
     * Seed the onboarding category templates. Names are translation keys,
     * translated into the user's language when a template is applied.
     */
    public function run(): void
    {
        $basic = [
            ['name' => 'Housing', 'type' => LineType::Fixed->value, 'icon' => 'home', 'color' => 'sky', 'hint' => 'Rent or mortgage-free housing costs paid from your own account.'],
            ['name' => 'Utilities', 'type' => LineType::Fixed->value, 'icon' => 'bolt', 'color' => 'amber', 'hint' => 'Electricity, gas, water, common costs. Leave 0 if they are paid from a joint account.'],
            ['name' => 'Phone and internet', 'type' => LineType::Fixed->value, 'icon' => 'smartphone', 'color' => 'indigo', 'hint' => 'Monthly mobile and home internet fees.'],
            ['name' => 'Groceries', 'type' => LineType::Variable->value, 'icon' => 'shopping_basket', 'color' => 'emerald', 'is_quick_entry' => true, 'hint' => 'Budget for food and household shopping you record day by day.'],
            ['name' => 'Transport', 'type' => LineType::Variable->value, 'icon' => 'directions_car', 'color' => 'orange', 'is_quick_entry' => true, 'hint' => 'Fuel, tickets, parking.'],
            ['name' => 'Entertainment', 'type' => LineType::Variable->value, 'icon' => 'movie', 'color' => 'fuchsia', 'is_quick_entry' => true, 'hint' => 'Going out, cinema, games.'],
            ['name' => 'Other', 'type' => LineType::Variable->value, 'icon' => 'more_horiz', 'color' => 'zinc', 'is_quick_entry' => true, 'hint' => 'Everything that does not fit elsewhere.'],
            ['name' => 'Reserve', 'type' => LineType::Sinking->value, 'icon' => 'shield', 'color' => 'teal', 'pocket' => ['name' => 'Reserve', 'is_reserve' => true], 'hint' => 'Monthly amount put aside for emergencies. The month-end leftover also goes here.'],
        ];

        $templates = [
            [
                'key' => 'basic',
                'name' => 'Basic',
                'description' => 'Housing, utilities, phone, groceries, transport, entertainment, other and a reserve pocket.',
                'items' => $basic,
            ],
            [
                'key' => 'with_loan',
                'name' => 'With a loan',
                'description' => 'Basic plus a loan line and a prepayment pocket.',
                'items' => [
                    ...$basic,
                    ['name' => 'Loan', 'type' => LineType::Loan->value, 'icon' => 'account_balance', 'color' => 'rose', 'loan' => true, 'hint' => 'The monthly installment including insurance. Principal and APR come later on the Pockets screen.'],
                    ['name' => 'Prepayment fund', 'type' => LineType::Sinking->value, 'icon' => 'trending_down', 'color' => 'lime', 'pocket' => ['name' => 'Prepayment fund', 'prepay_step' => 500_000], 'hint' => 'Saved monthly for prepaying the loan; closing signals at every 500 000 Ft.'],
                ],
            ],
            [
                'key' => 'couple',
                'name' => 'Living as a couple',
                'description' => 'Basic plus a shared contribution and a shared pocket. Only enter what you pay from your own account.',
                'items' => [
                    ...$basic,
                    ['name' => 'Shared contribution', 'type' => LineType::Transfer->value, 'icon' => 'group', 'color' => 'violet', 'hint' => 'What you transfer to the joint account every month. Costs paid from there (e.g. utilities) should be 0 above.'],
                    ['name' => 'Shared pocket', 'type' => LineType::Sinking->value, 'icon' => 'favorite', 'color' => 'pink', 'pocket' => ['name' => 'Shared pocket', 'is_shared' => true], 'hint' => 'Your share of the joint savings.'],
                ],
            ],
            [
                'key' => 'empty',
                'name' => 'Empty',
                'description' => 'Only the line types, no categories.',
                'items' => [],
            ],
        ];

        foreach ($templates as $sort => $template) {
            CategoryTemplate::query()->updateOrCreate(['key' => $template['key']], [...$template, 'sort' => $sort]);
        }
    }
}
