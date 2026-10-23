<?php

use Illuminate\Support\Facades\Blade;

it('draws Morzsa in every mood with a spoken label', function (string $mood, string $label): void {
    app()->setLocale('hu');

    $html = Blade::render('<x-morzsa :mood="$mood" :size="72" />', ['mood' => $mood]);

    expect($html)->toContain('role="img"')
        ->toContain('aria-label="'.$label.'"')
        ->toContain('width: 72px');
})->with([
    ['nyugodt', 'Morzsa nyugodtan ül.'],
    ['udvozlo', 'Morzsa integet.'],
    ['vidam', 'Morzsa örül.'],
    ['kivancsi', 'Morzsa kíváncsian néz.'],
    ['elgondolkodo', 'Morzsa gondolkodik.'],
    ['unneplo', 'Morzsa ünnepel.'],
    ['alvo', 'Morzsa alszik.'],
    ['buszke', 'Morzsa büszkén kihúzza magát.'],
    ['meglepett', 'Morzsa meglepődik.'],
    ['szamolos', 'Morzsa számol.'],
]);

it('falls back to the calm mood for an unknown one', function (): void {
    app()->setLocale('hu');

    expect(Blade::render('<x-morzsa mood="valami" />'))->toContain('Morzsa nyugodtan ül.');
});
