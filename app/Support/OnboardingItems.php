<?php

namespace App\Support;

use App\Enums\LineType;

/**
 * The plan lines the setup wizard offers, grouped by the wizard step that asks about them.
 * Names and hints are translation keys, translated when the lines are created.
 *
 * @phpstan-type Item array{name: string, type: string, icon: string, color: string, hint: string, is_quick_entry?: bool, loan?: bool, pocket?: array{name: string, is_reserve?: bool, is_shared?: bool, prepay_step?: int}}
 */
final class OnboardingItems
{
    /**
     * @return array<string, list<Item>>
     */
    public static function groups(): array
    {
        return [
            'fixed' => [
                ['name' => 'Housing', 'type' => LineType::Fixed->value, 'icon' => 'home', 'color' => 'sky', 'hint' => 'Rent or other housing costs paid from your own account.'],
                ['name' => 'Utilities', 'type' => LineType::Fixed->value, 'icon' => 'bolt', 'color' => 'amber', 'hint' => 'Electricity, gas, water, common costs. Leave 0 if they are paid from a joint account.'],
                ['name' => 'Phone and internet', 'type' => LineType::Fixed->value, 'icon' => 'smartphone', 'color' => 'indigo', 'hint' => 'Monthly mobile and home internet fees.'],
            ],
            'shared' => [
                ['name' => 'Shared contribution', 'type' => LineType::Transfer->value, 'icon' => 'group', 'color' => 'violet', 'hint' => 'What you transfer to the joint account every month. Costs paid from there (e.g. utilities) should be 0 above.'],
                ['name' => 'Shared pocket', 'type' => LineType::Sinking->value, 'icon' => 'favorite', 'color' => 'pink', 'pocket' => ['name' => 'Shared pocket', 'is_shared' => true], 'hint' => 'Your share of the joint savings.'],
            ],
            'loan' => [
                ['name' => 'Loan', 'type' => LineType::Loan->value, 'icon' => 'account_balance', 'color' => 'rose', 'loan' => true, 'hint' => 'The monthly installment including insurance. It comes off the plan by itself, you do not record it. The principal and the APR can be added later: Plan, Loan repayments.'],
                ['name' => 'Prepayment fund', 'type' => LineType::Sinking->value, 'icon' => 'trending_down', 'color' => 'lime', 'pocket' => ['name' => 'Prepayment fund', 'prepay_step' => 500_000], 'hint' => 'Put aside monthly for prepaying the loan. When it reaches 500 000 Ft, the app shows how much the installment would drop.'],
            ],
            'daily' => [
                ['name' => 'Groceries', 'type' => LineType::Variable->value, 'icon' => 'shopping_basket', 'color' => 'emerald', 'is_quick_entry' => true, 'hint' => 'Budget for food and household shopping you record day by day.'],
                ['name' => 'Transport', 'type' => LineType::Variable->value, 'icon' => 'directions_car', 'color' => 'orange', 'is_quick_entry' => true, 'hint' => 'Fuel, tickets, parking.'],
                ['name' => 'Entertainment', 'type' => LineType::Variable->value, 'icon' => 'movie', 'color' => 'fuchsia', 'is_quick_entry' => true, 'hint' => 'Going out, cinema, games.'],
                ['name' => 'Other', 'type' => LineType::Variable->value, 'icon' => 'more_horiz', 'color' => 'zinc', 'is_quick_entry' => true, 'hint' => 'Everything that does not fit elsewhere.'],
            ],
            'reserve' => [
                ['name' => 'Reserve', 'type' => LineType::Sinking->value, 'icon' => 'shield', 'color' => 'teal', 'pocket' => ['name' => 'Reserve', 'is_reserve' => true], 'hint' => 'Monthly amount put aside for emergencies. The month-end leftover also goes here.'],
            ],
        ];
    }

    /**
     * All lines in wizard order, each tagged with its group.
     *
     * @return list<array{group: string, item: Item}>
     */
    public static function all(): array
    {
        $all = [];

        foreach (self::groups() as $group => $items) {
            foreach ($items as $item) {
                $all[] = ['group' => $group, 'item' => $item];
            }
        }

        return $all;
    }
}
