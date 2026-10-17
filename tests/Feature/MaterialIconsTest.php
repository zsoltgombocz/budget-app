<?php

use App\Support\Icons;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\Finder\Finder;

/*
 * The icon font is a subset (resources/fonts/material-icons.txt + scripts/fetch-icons.sh): a name
 * missing from it renders as letters ("K K" instead of the arrows). These tests collect the
 * literal icon names the code uses and check each is bundled. Names built at runtime (a
 * category's own icon, an Alpine variable) cannot be seen here; literals and simple arrays are.
 */

const ICON_NAME = '[a-z][a-z0-9_]*';

/**
 * @return list<string>
 */
function bundledIconNames(): array
{
    return array_values(array_filter(array_map(trim(...), explode(',', (string) file_get_contents(resource_path('fonts/material-icons.txt'))))));
}

/**
 * The opening tags of Blade components (<x-…>) in a template, read up to the closing ">" that is
 * outside quotes, since Alpine expressions in attributes may contain "=>".
 *
 * @return list<array{name: string, tag: string}>
 */
function componentTags(string $source): array
{
    $tags = [];
    preg_match_all('/<x-([\w.:-]+)/', $source, $starts, PREG_OFFSET_CAPTURE);

    foreach ($starts[0] as $index => [$start, $offset]) {
        $quote = null;
        $length = strlen($source);
        for ($position = $offset + strlen($start); $position < $length; $position++) {
            $character = $source[$position];
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }
            } elseif ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '>') {
                break;
            }
        }

        $tags[] = ['name' => $starts[1][$index][0], 'tag' => substr($source, $offset, $position - $offset + 1)];
    }

    return $tags;
}

/**
 * @return list<string>
 */
function quotedIconNames(string $expression): array
{
    // Not array keys: $card['icon'], ['name' => $name].
    preg_match_all("/(?<!\\[)'(".ICON_NAME.")'(?!\\]|\\s*=>)/", $expression, $matches);

    return $matches[1];
}

/**
 * Literal icon names in one template, by rule.
 *
 * @return list<string>
 */
function iconNamesIn(string $source): array
{
    $names = [];

    foreach (componentTags($source) as ['name' => $component, 'tag' => $tag]) {
        $attributes = $component === 'ui.icon' ? 'icon|icon-trailing|name' : 'icon|icon-trailing';
        // icon="savings" (a Blade echo or anything else non-literal is skipped).
        preg_match_all('/(?<![\w:-])(?:'.$attributes.')="('.ICON_NAME.')"/', $tag, $plain);
        // :icon="$idea ? 'lightbulb' : 'bug_report'", :name="… ?? 'more_horiz'".
        preg_match_all('/(?<![\w-]):(?:'.$attributes.')="([^"]*)"/', $tag, $bound);
        array_push($names, ...$plain[1]);
        foreach ($bound[1] as $expression) {
            array_push($names, ...quotedIconNames($expression));
        }
    }

    // 'icon' => 'bedtime' in arrays, icon: 'undo' in toast dispatches.
    preg_match_all("/'icon'\s*=>\s*'(".ICON_NAME.")'|\bicon:\s*'(".ICON_NAME.")'/", $source, $arrays);
    array_push($names, ...array_filter([...$arrays[1], ...$arrays[2]]));

    // <span class="ms …">ios_share</span>
    preg_match_all('/<span class="ms[\s"][^>]*>('.ICON_NAME.')<\/span>/', $source, $spans);
    array_push($names, ...$spans[1]);

    // Defaults and checks in Alpine code: toast.icon ?? 'check_circle', icon === 'error',
    // detail.icon ?? (this.danger ? 'delete' : 'info').
    preg_match_all("/\\bicon\\s*(?:\\?\\?|===)\\s*(\\([^)]*\\)|'[^']*')/", $source, $defaults);
    foreach ($defaults[1] as $expression) {
        array_push($names, ...quotedIconNames($expression));
    }

    // @foreach ([[-1, 'keyboard_arrow_up', …], …] as [$direction, $arrow, …]) feeding :name="$arrow".
    preg_match_all('/@foreach\s*\((\[.*\])\s+as\s+\[([^\]]*)\]\)/', $source, $loops, PREG_SET_ORDER);
    foreach ($loops as [, $array, $variables]) {
        preg_match_all('/\$\w+/', $variables, $declared);
        foreach ($declared[0] as $variable) {
            if (preg_match('/:(?:icon|name)="'.preg_quote($variable, '/').'"/', $source) === 1) {
                array_push($names, ...quotedIconNames($array));
            }
        }
    }

    return $names;
}

it('bundles every icon name the templates use literally', function (): void {
    $missing = [];
    $bundled = bundledIconNames();
    $files = Finder::create()->files()->in(resource_path('views'))->exclude('flux')->name('*.blade.php');

    foreach ($files as $file) {
        foreach (array_unique(iconNamesIn($file->getContents())) as $name) {
            if (! in_array($name, $bundled, true)) {
                $missing[] = $name.' ('.$file->getRelativePathname().')';
            }
        }
    }

    expect($missing)->toBe([]);
});

it('finds the icon names in the shapes the templates use', function (): void {
    $source = <<<'BLADE'
        <x-ui.icon name="keyboard_arrow_up" :size="20" />
        <x-ui.button variant="tonal" icon="check" x-on:click="items.map(i => i)">Done</x-ui.button>
        <x-ui.icon-tile :icon="$idea ? 'lightbulb' : 'bug_report'" />
        <x-ui.input name="password" />
        @foreach ([[-1, 'keyboard_arrow_down', __('Down')]] as [$direction, $arrow, $label])
            <x-ui.icon :name="$arrow" />
        @endforeach
        $this->dispatch('app-toast', title: 'Done', icon: 'undo');
        <span class="ms" aria-hidden="true">ios_share</span>
        return this.toast?.icon ?? 'check_circle'
        BLADE;

    expect(iconNamesIn($source))->toEqualCanonicalizing(['keyboard_arrow_up', 'check', 'lightbulb', 'bug_report', 'keyboard_arrow_down', 'undo', 'ios_share', 'check_circle']);
});

it('bundles the icons the PHP code hands to the views', function (): void {
    $names = [...Icons::CATEGORY, Icons::FALLBACK];
    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        preg_match_all("/'icon'\s*=>\s*'(".ICON_NAME.")'/", $file->getContents(), $matches);
        array_push($names, ...$matches[1]);
    }

    expect(array_values(array_diff(array_unique($names), bundledIconNames())))->toBe([]);
});

it('bundles the default icons of the shared components', function (): void {
    $html = collect(['neutral', 'warn', 'danger', 'success'])
        ->map(fn (string $tone): string => Blade::render('<x-ui.callout :tone="$tone" title="T">Text</x-ui.callout>', ['tone' => $tone]))
        ->implode('');

    preg_match_all('/<span class="ms[^"]*"[^>]*>('.ICON_NAME.')<\/span>/', $html, $matches);

    expect($matches[1])->toHaveCount(4)
        ->and(array_values(array_diff($matches[1], bundledIconNames())))->toBe([]);
});
