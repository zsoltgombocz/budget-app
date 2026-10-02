<?php

namespace Database\Seeders;

use App\Models\CategoryTemplate;
use App\Support\OnboardingItems;
use Illuminate\Database\Seeder;

class CategoryTemplateSeeder extends Seeder
{
    /**
     * Seed the category templates (fixed starting plans, e.g. for tests and the API of
     * ApplyCategoryTemplate). The setup wizard composes its plan from OnboardingItems directly.
     */
    public function run(): void
    {
        // The fixed templates keep their classic lines; the optional ones are the wizard's.
        $groups = array_map(fn (array $items): array => array_values(array_filter($items, fn (array $item): bool => ! ($item['off'] ?? false))), OnboardingItems::groups());
        $basic = [...$groups['fixed'], ...$groups['daily'], ...$groups['reserve']];

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
                    ...$groups['loan'],
                ],
            ],
            [
                'key' => 'couple',
                'name' => 'Living as a couple',
                'description' => 'Basic plus a shared contribution and a shared pocket. Only enter what you pay from your own account.',
                'items' => [
                    ...$basic,
                    ...$groups['shared'],
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
