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

it('writes section labels in normal case and keeps the uppercase variant for plan groups', function (): void {
    expect(Blade::render('<x-ui.section-label label="Recent movements" />'))->not->toContain('uppercase')
        ->and(Blade::render('<x-ui.form-group title="Basics">Rows</x-ui.form-group>'))->toContain('Basics')->not->toContain('uppercase')
        ->and(Blade::render('<x-ui.section-label group label="Fixed costs" aside="3 items" />'))->toContain('uppercase')->toContain('3 items');
});

it('binds a switch to browser state when it gets an Alpine model', function (): void {
    $html = Blade::render('<x-ui.toggle model="enabled" aria-label="Notifications" />');

    expect($html)->toContain('role="switch"')
        ->toContain(':aria-checked="(enabled) ? \'true\' : \'false\'"')
        ->toContain('aria-label="Notifications"')
        ->toContain('before:-inset-2.5');
});

it('names a progress bar when it gets a label', function (): void {
    expect(Blade::render('<x-ui.bar :value="0.5" label="Groceries" />'))->toContain('role="progressbar"')->toContain('aria-label="Groceries"')->toContain('aria-valuenow="50"')
        ->and(Blade::render('<x-ui.bar :value="0.5" />'))->not->toContain('aria-label');
});

it('renders a text link underlined in the accent colour, as an anchor or a button', function (): void {
    $anchor = Blade::render('<x-ui.link href="/install">Detailed guide</x-ui.link>');
    $button = Blade::render('<x-ui.link icon="add" x-on:click="openNew()">New item</x-ui.link>');

    expect($anchor)->toContain('<a href="/install"')->toContain('text-accent-strong')->toContain('underline')->toContain('Detailed guide')
        ->and($button)->toContain('<button type="button"')->toContain('x-on:click="openNew()"')->toContain('underline')->toContain('>add</span>');
});

it('gives the small controls a 44px touch target', function (): void {
    expect(Blade::render('<x-install-card />'))->toMatch('/data-test="install-dismiss"/')->toMatch('/<button[^>]*size-11[^>]*data-test="install-dismiss"/')
        ->and(Blade::render('<x-no-spend-button :marked="true" />'))->toMatch('/<button[^>]*h-11[^>]*data-test="undo-no-spend"/')
        ->and(Blade::render('<x-toast-host />'))->toMatch('/<button[^>]*size-11[^>]*data-test="toast-close"/');
});

it('hides the inline icons of the install steps from screen readers', function (): void {
    $html = Blade::render('<x-install-steps />');

    expect(substr_count($html, 'class="ms'))->toBe(substr_count($html, 'aria-hidden="true"'));
});

it('listens for navigation on the confirm dialog without leaking a document listener', function (): void {
    expect(Blade::render('<x-confirm-dialog />'))
        ->toContain('x-on:livewire:navigate.document="open && answer(false)"')
        ->not->toContain('document.addEventListener');
});
