<?php

namespace App\Support;

/**
 * Morzsa's things: worn (head, neck, face) or put in the room. Bought with acorns only.
 */
final class Wardrobe
{
    /**
     * @return array<string, array{name: string, slot: string, price: int}>
     */
    public static function items(): array
    {
        return [
            'hat' => ['name' => __('Knitted hat'), 'slot' => 'head', 'price' => 30],
            'scarf' => ['name' => __('Striped scarf'), 'slot' => 'neck', 'price' => 30],
            'glasses' => ['name' => __('Round glasses'), 'slot' => 'face', 'price' => 40],
            'lamp' => ['name' => __('Floor lamp'), 'slot' => 'room-lamp', 'price' => 20],
            'plant' => ['name' => __('Plant'), 'slot' => 'room-plant', 'price' => 40],
            'shelf' => ['name' => __('Bookshelf'), 'slot' => 'room-shelf', 'price' => 80],
        ];
    }

    /**
     * @return array{name: string, slot: string, price: int}|null
     */
    public static function find(string $key): ?array
    {
        return self::items()[$key] ?? null;
    }
}
