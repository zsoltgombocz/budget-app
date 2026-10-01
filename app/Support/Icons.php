<?php

namespace App\Support;

/**
 * Material Symbols Rounded names offered for categories. Every name here must also be
 * listed in resources/fonts/material-icons.txt (the bundled font subset).
 */
final class Icons
{
    /**
     * @var list<string>
     */
    public const array CATEGORY = [
        'shopping_basket', 'shopping_cart', 'local_gas_station', 'directions_car', 'directions_bus', 'train',
        'two_wheeler', 'local_parking', 'garage', 'home', 'bolt', 'water_drop', 'wifi', 'smartphone', 'devices',
        'restaurant', 'local_cafe', 'fastfood', 'local_bar', 'movie', 'music_note', 'sports_esports',
        'fitness_center', 'sports_soccer', 'menu_book', 'school', 'child_care', 'pets', 'medical_services',
        'local_pharmacy', 'spa', 'content_cut', 'checkroom', 'local_laundry_service', 'cleaning_services',
        'handyman', 'card_giftcard', 'redeem', 'celebration', 'flight', 'beach_access', 'park', 'subscriptions',
        'cloud', 'receipt', 'credit_card', 'account_balance', 'payments', 'savings', 'shield', 'trending_down',
        'show_chart', 'work', 'group', 'favorite', 'volunteer_activism', 'more_horiz',
    ];

    public const string FALLBACK = 'more_horiz';

    /**
     * Heroicons names used before the redesign → Material Symbols.
     *
     * @var array<string, string>
     */
    public const array FROM_HEROICONS = [
        'home' => 'home',
        'bolt' => 'bolt',
        'device-phone-mobile' => 'smartphone',
        'shopping-cart' => 'shopping_basket',
        'truck' => 'directions_car',
        'film' => 'movie',
        'ellipsis-horizontal' => 'more_horiz',
        'shield-check' => 'shield',
        'banknotes' => 'account_balance',
        'arrow-trending-down' => 'trending_down',
        'users' => 'group',
        'heart' => 'favorite',
        'tag' => 'more_horiz',
    ];

    public static function forCategory(?string $icon): string
    {
        if ($icon === null || $icon === '') {
            return self::FALLBACK;
        }

        if (in_array($icon, self::CATEGORY, true)) {
            return $icon;
        }

        return self::FROM_HEROICONS[$icon] ?? self::FALLBACK;
    }
}
