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
     * What each category icon shows, in words (translation keys), for screen readers.
     *
     * @var array<string, string>
     */
    public const array LABELS = [
        'shopping_basket' => 'Shopping basket',
        'shopping_cart' => 'Shopping cart',
        'local_gas_station' => 'Fuel pump',
        'directions_car' => 'Car',
        'directions_bus' => 'Bus',
        'train' => 'Train',
        'two_wheeler' => 'Motorbike',
        'local_parking' => 'Parking',
        'garage' => 'Garage',
        'home' => 'House',
        'bolt' => 'Lightning',
        'water_drop' => 'Water drop',
        'wifi' => 'Wi-Fi',
        'smartphone' => 'Phone',
        'devices' => 'Devices',
        'restaurant' => 'Restaurant',
        'local_cafe' => 'Coffee',
        'fastfood' => 'Fast food',
        'local_bar' => 'Drink',
        'movie' => 'Film',
        'music_note' => 'Music',
        'sports_esports' => 'Game controller',
        'fitness_center' => 'Gym',
        'sports_soccer' => 'Football',
        'menu_book' => 'Book',
        'school' => 'School',
        'child_care' => 'Child',
        'pets' => 'Pet',
        'medical_services' => 'Doctor',
        'local_pharmacy' => 'Pharmacy',
        'spa' => 'Wellness',
        'content_cut' => 'Scissors',
        'checkroom' => 'Clothes',
        'local_laundry_service' => 'Laundry',
        'cleaning_services' => 'Cleaning',
        'handyman' => 'Tools',
        'card_giftcard' => 'Gift',
        'redeem' => 'Gift box',
        'celebration' => 'Party',
        'flight' => 'Plane',
        'beach_access' => 'Beach',
        'park' => 'Tree',
        'subscriptions' => 'Subscriptions',
        'cloud' => 'Cloud',
        'receipt' => 'Receipt',
        'credit_card' => 'Bank card',
        'account_balance' => 'Bank',
        'payments' => 'Cash',
        'savings' => 'Piggy bank',
        'shield' => 'Shield',
        'trending_down' => 'Falling chart',
        'show_chart' => 'Chart',
        'work' => 'Briefcase',
        'group' => 'People',
        'favorite' => 'Heart',
        'volunteer_activism' => 'Donation',
        'more_horiz' => 'Three dots',
    ];

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

    /**
     * The translated name of what a category icon shows; the raw icon name for an unknown one.
     */
    public static function label(string $icon): string
    {
        return isset(self::LABELS[$icon]) ? __(self::LABELS[$icon]) : $icon;
    }

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
