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
            ['name' => 'Housing', 'type' => LineType::Fixed->value, 'icon' => 'home', 'color' => 'sky'],
            ['name' => 'Utilities', 'type' => LineType::Fixed->value, 'icon' => 'bolt', 'color' => 'amber'],
            ['name' => 'Phone and internet', 'type' => LineType::Fixed->value, 'icon' => 'device-phone-mobile', 'color' => 'indigo'],
            ['name' => 'Groceries', 'type' => LineType::Variable->value, 'icon' => 'shopping-cart', 'color' => 'emerald', 'is_quick_entry' => true],
            ['name' => 'Transport', 'type' => LineType::Variable->value, 'icon' => 'truck', 'color' => 'orange', 'is_quick_entry' => true],
            ['name' => 'Entertainment', 'type' => LineType::Variable->value, 'icon' => 'film', 'color' => 'fuchsia', 'is_quick_entry' => true],
            ['name' => 'Other', 'type' => LineType::Variable->value, 'icon' => 'ellipsis-horizontal', 'color' => 'zinc', 'is_quick_entry' => true],
            ['name' => 'Reserve', 'type' => LineType::Sinking->value, 'icon' => 'shield-check', 'color' => 'teal', 'pocket' => ['name' => 'Reserve', 'is_reserve' => true]],
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
                    ['name' => 'Loan', 'type' => LineType::Loan->value, 'icon' => 'banknotes', 'color' => 'rose', 'loan' => true],
                    ['name' => 'Prepayment fund', 'type' => LineType::Sinking->value, 'icon' => 'arrow-trending-down', 'color' => 'lime', 'pocket' => ['name' => 'Prepayment fund', 'prepay_step' => 500_000]],
                ],
            ],
            [
                'key' => 'couple',
                'name' => 'Living as a couple',
                'description' => 'Basic plus a shared contribution and a shared pocket.',
                'items' => [
                    ...$basic,
                    ['name' => 'Shared contribution', 'type' => LineType::Transfer->value, 'icon' => 'users', 'color' => 'violet'],
                    ['name' => 'Shared pocket', 'type' => LineType::Sinking->value, 'icon' => 'heart', 'color' => 'pink', 'pocket' => ['name' => 'Shared pocket', 'is_shared' => true]],
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
