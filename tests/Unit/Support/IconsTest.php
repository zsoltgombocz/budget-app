<?php

use App\Support\Icons;

it('names every category icon in words, with a Hungarian translation', function (): void {
    $translations = json_decode((string) file_get_contents(lang_path('hu.json')), true);

    foreach (Icons::CATEGORY as $icon) {
        expect(Icons::LABELS)->toHaveKey($icon)
            ->and($translations)->toHaveKey(Icons::LABELS[$icon]);
    }

    expect(array_diff(array_keys(Icons::LABELS), Icons::CATEGORY))->toBe([]);
});

it('labels an icon in the app language and falls back to the raw name', function (): void {
    app()->setLocale('hu');

    expect(Icons::label('shopping_cart'))->toBe('Bevásárlókocsi')
        ->and(Icons::label('not_an_icon'))->toBe('not_an_icon');
});

it('shows the retired three-dots "Other" icon as the category icon, so it is not mistaken for a menu button', function (): void {
    expect(Icons::forCategory('more_horiz'))->toBe('category')
        ->and(Icons::forCategory(null))->toBe('category')
        ->and(Icons::CATEGORY)->not->toContain('more_horiz');
});
