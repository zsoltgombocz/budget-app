<?php

use App\Enums\Currency;
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

it('keeps the cents of a euro amount so the amounts of a summary add up on screen', function (): void {
    app()->setLocale('hu');
    $eur = Currency::EUR;

    // 1 789,40 − 1 625,60 = 163,80: rounded to whole euros it would read 1 789 − 1 626 = 164.
    expect(money_number(178_940, $eur))->toBe("1\u{00A0}789,40")
        ->and(money_number(-16_380, $eur))->toBe("\u{2212}163,80")
        ->and(money_number(178_940, Currency::HUF))->toBe("178\u{00A0}940")
        ->and(Blade::render('<x-ui.amount :value="16380" :currency="$eur" />', ['eur' => $eur]))->toContain('>163,80<')
        ->and(money_number(-0, $eur))->toBe('0,00');
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
    expect(Blade::render('<x-install-card />'))->toMatch('/data-test="install-dismiss"/')->toMatch('/<button[^>]*before:-inset-1[^>]*data-test="install-dismiss"/')
        ->and(Blade::render('<x-no-spend-button :marked="true" />'))->toMatch('/<button[^>]*h-11[^>]*data-test="undo-no-spend"/')
        ->and(Blade::render('<x-toast-host />'))->toMatch('/<button[^>]*h-11[^>]*data-test="toast-undo"/');
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

it('offers the button variants of the design system and falls back to primary for unknown ones', function (): void {
    $render = fn (string $variant, string $size = 'md'): string => Blade::render('<x-ui.button :variant="$variant" :size="$size">Go</x-ui.button>', ['variant' => $variant, 'size' => $size]);

    expect($render('tonal'))->toContain('bg-accent/14')->toContain('text-accent')->toContain('data-variant="tonal"')
        ->and($render('text'))->toContain('bg-transparent')->toContain('text-ink-2')->toContain('px-3')->not->toContain('px-4')
        ->and($render('secondary'))->toContain('bg-surface-2')->toContain('in-[.bg-surface-2]:bg-ink/6')->toContain('in-data-callout:bg-ink/8')
        ->and($render('danger-solid'))->toContain('bg-danger-solid')->toContain('text-danger-ink')
        ->and($render('light'))->toContain('data-variant="primary"')
        ->and($render('ghost'))->toContain('data-variant="primary"');
});

it('sizes buttons as rounded rectangles and keeps a 44px touch target on the small one', function (): void {
    $render = fn (string $size): string => Blade::render('<x-ui.button variant="tonal" :size="$size" icon="check">Done</x-ui.button>', ['size' => $size]);

    expect($render('lg'))->toContain('h-14')->toContain('rounded-btn')->toContain('font-size: 22px')
        ->and($render('md'))->toContain('h-11')->toContain('rounded-control')->toContain('text-[15px]')->toContain('font-size: 20px')
        ->and($render('sm'))->toContain('h-9')->toContain('rounded-small')->toContain('before:-inset-y-1')->toContain('font-size: 18px')
        ->and($render('sm').$render('md').$render('lg'))->not->toContain('rounded-full');
});

it('renders a callout in its tone with a default icon, a title, the text and its actions', function (): void {
    $html = Blade::render('<x-ui.callout tone="danger" title="Over the plan">Cut back.<x-slot:actions><x-ui.button variant="secondary" size="sm">Cover</x-ui.button></x-slot:actions></x-ui.callout>');

    expect($html)->toContain('data-callout="danger"')->toContain('bg-danger/10')->toContain('>error</span>')
        ->toContain('Over the plan')->toContain('Cut back.')->toContain('Cover')
        ->and(Blade::render('<x-ui.callout>Info</x-ui.callout>'))->toContain('data-callout="neutral"')->toContain('bg-surface-2')->toContain('>info</span>')
        ->and(Blade::render('<x-ui.callout tone="warn" icon="schedule">Soon</x-ui.callout>'))->toContain('bg-warn/10')->toContain('>schedule</span>');
});

it('renders a section header as a heading with its subtitle and action', function (): void {
    $html = Blade::render('<x-ui.section-header title="Loans" subtitle="2 active"><x-slot:actions><x-ui.button variant="secondary" size="sm" icon="add">New loan</x-ui.button></x-slot:actions></x-ui.section-header>');

    expect($html)->toMatch('/<h2 class="[^"]*text-xl font-semibold[^"]*">Loans<\/h2>/')->toContain('2 active')->toContain('New loan')
        ->and(Blade::render('<x-ui.section-header title="Loans" as="h3" />'))->toContain('<h3')->not->toContain('2 active');
});

it('renders underline tabs wired to their panels', function (): void {
    $html = Blade::render('<x-ui.tabs id="pocket" model="tab" :options="[\'money\' => \'Move money\', \'settings\' => \'Settings\']" />');

    expect($html)->toContain('role="tablist"')
        ->and(substr_count($html, 'role="tab"'))->toBe(2)
        ->and($html)->toContain('id="pocket-tab-money"')->toContain('aria-controls="pocket-panel-settings"')
        ->toContain(':aria-selected="tab === \'money\' ? \'true\' : \'false\'"')->toContain('after:bg-accent');
});

it('shows plan against actual as a table with neutral rows and only the deviation coloured', function (): void {
    $html = Blade::render('<x-ui.plan-actual-table caption="Plan vs. actual" :rows="$rows" :total="$total" />', [
        'rows' => [
            ['name' => 'Groceries', 'planned' => 100_000, 'actual' => 112_000, 'note' => 'over by 12 000', 'noteTone' => 'danger', 'key' => 1, 'test' => 'row-over'],
            ['name' => 'Fuel', 'planned' => 60_000, 'actual' => 45_000, 'key' => 2],
            ['name' => 'Fun', 'planned' => 10_000, 'actual' => 10_000, 'key' => 3],
        ],
        'total' => ['name' => 'Budgets in total', 'planned' => 170_000, 'actual' => 167_000],
    ]);

    expect($html)->toContain('role="table"')->toContain('aria-label="Plan vs. actual"')
        ->and(substr_count($html, 'role="row"'))->toBe(5)
        ->and(substr_count($html, 'role="columnheader"'))->toBe(4)
        ->and($html)->toContain('data-test="row-over"')->toContain('over by 12 000')
        ->toContain('>'."\u{2212}".money_number(12_000).'<')
        ->toContain('>+'.money_number(15_000).'<')
        ->toContain('>+'.money_number(3_000).'<')
        ->toContain('grid-cols-subgrid')
        ->not->toContain('bg-danger/10')->not->toContain('bg-accent/8');

    preg_match_all('/<span role="cell" class="([^"]*)" data-test="plan-actual-deviation">/', $html, $deviations);

    expect($deviations[1][0])->toContain('text-danger')
        ->and($deviations[1][1])->toContain('text-accent')
        ->and($deviations[1][2])->toContain('text-muted');
});

it('gives the icon tile a warn tone and a radius from the token scale', function (): void {
    expect(Blade::render('<x-ui.icon-tile icon="schedule" tone="warn" />'))->toContain('bg-warn/14')->toContain('text-warn')->toContain('rounded-control')
        ->and(Blade::render('<x-ui.icon-tile icon="savings" :size="56" />'))->toContain('rounded-group')
        ->and(Blade::render('<x-ui.icon-tile icon="savings" :size="36" />'))->toContain('rounded-small');
});

it('renders a compact empty state with the tile on the left and no dashed outline', function (): void {
    $compact = Blade::render('<x-ui.empty-state compact icon="key" title="No passkey yet">Add one below.</x-ui.empty-state>');

    expect($compact)->toContain('data-empty-state="compact"')->toContain('No passkey yet')->toContain('Add one below.')->not->toContain('border-dashed')
        ->and(Blade::render('<x-ui.empty-state icon="savings" title="No pockets">Text</x-ui.empty-state>'))->toContain('border-dashed');
});

it('keeps the sheet title on one line, grows a sheet without a pad and puts the danger zone at the end', function (): void {
    $short = Blade::render('<x-ui.sheet show="open" close="open = false" label="Edit spending">Body<x-slot:danger><x-ui.button variant="danger" size="md">Delete</x-ui.button></x-slot:danger></x-ui.sheet>');
    $padded = Blade::render('<x-ui.sheet show="open" close="open = false" label="Edit">Body<x-slot:pad><div>pad</div></x-slot:pad></x-ui.sheet>');
    $shortWithPad = Blade::render('<x-ui.sheet show="open" close="open = false" label="Edit" :full="false">Body<x-slot:pad><div>pad</div></x-slot:pad></x-ui.sheet>');

    expect($short)->toMatch('/<h2 class="truncate [^"]*" data-test="sheet-title">Edit spending<\/h2>/')->not->toContain('line-clamp-2')
        ->toContain('max-h-[calc(100dvh-var(--safe-top)-0.75rem)]')->not->toContain('top-[calc(var(--safe-top)+0.75rem)]')
        ->toMatch('/Body\s*<div class="mt-6" data-test="sheet-danger">/')
        ->and($padded)->toContain('top-[calc(var(--safe-top)+0.75rem)]')
        ->and($shortWithPad)->toContain(':class="active && \'top-[calc(var(--safe-top)+0.75rem)]\'"');
});

it('confirms a destructive action with the solid danger button and the given icon', function (): void {
    $html = Blade::render('<x-confirm-dialog />');

    expect($html)->toContain('this.icon = detail.icon ?? (this.danger ? \'delete\' : \'info\')')
        ->toContain('x-text="icon"')
        ->toMatch('/<template x-if="danger">\s*<button[^>]*bg-danger-solid[^>]*data-test="confirm-ok"/')
        ->not->toContain('!bg-danger');
});

it('lays the toast out on one row with an inline undo and no close button', function (): void {
    $html = Blade::render('<x-toast-host />');

    expect($html)->toContain('data-test="toast-undo"')->not->toContain('data-test="toast-close"')
        ->toContain('bg-(--toast-bg)')->toContain('line-clamp-2')
        ->toContain('bottom-[calc(var(--tabbar-h)+12px+env(safe-area-inset-bottom))]')
        ->toContain('app-toast-visibility');
});

it('shows the no-spend control as a text button, and once marked as a row with undo', function (): void {
    expect(Blade::render('<x-no-spend-button />'))->toContain('data-variant="text"')->toContain('>do_not_disturb_on</span>')
        ->and(Blade::render('<x-no-spend-button :marked="true" />'))->toContain('>check_circle</span>')->toMatch('/<button[^>]*data-test="undo-no-spend"[^>]*data-variant="text"/');
});
