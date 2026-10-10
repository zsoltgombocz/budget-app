<?php

use Illuminate\Support\Facades\Blade;

it('renders the sheet as a named modal dialog with the shared dialog behaviour', function (): void {
    $html = Blade::render('<x-ui.sheet show="open" close="open = false" label="Edit">Body</x-ui.sheet>');

    expect($html)
        ->toContain('role="dialog"')
        ->toContain('aria-modal="true"')
        ->toContain('aria-label="Edit"')
        ->toContain('x-data="appDialog"')
        ->toContain('dialogSync(open)')
        ->toContain('dialogEscape($event) && (open = false)')
        ->not->toContain('overflow-hidden\', open');
});

it('names a sheet from an Alpine title expression', function (): void {
    expect(Blade::render('<x-ui.sheet show="open" close="open = false" title="heading">Body</x-ui.sheet>'))
        ->toContain(':aria-label="heading"')
        ->toContain('x-text="heading"');
});

it('exposes the selection state of choices, segments and switches', function (): void {
    expect(Blade::render('<x-ui.choice :selected="true">A</x-ui.choice>'))->toContain('aria-pressed="true"')
        ->and(Blade::render('<x-ui.choice>A</x-ui.choice>'))->toContain('aria-pressed="false"')
        ->and(Blade::render('<x-ui.toggle :on="true" aria-label="Reminder" />'))->toContain('role="switch"')->toContain('aria-checked="true"');

    $segmented = Blade::render('<x-ui.segmented model="mode" :options="[\'a\' => \'A\', \'b\' => \'B\']" />');

    expect($segmented)->toContain('role="radiogroup"')
        ->and(substr_count($segmented, 'role="radio"'))->toBe(2)
        ->and($segmented)->toContain(':aria-checked="mode === \'a\' ? \'true\' : \'false\'"');
});

it('shows an amount with a separate currency symbol and an optional goal', function (): void {
    $html = Blade::render('<x-ui.amount :value="45000000" :of="50000000" size="lg" />');

    expect($html)->toContain('>/ '.money_number(50_000_000).'<')
        ->and($html)->toContain('>'.user_currency()->symbol().'<');
});
