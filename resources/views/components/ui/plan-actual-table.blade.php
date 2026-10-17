@props([
    'rows',
    'total' => null,
    'caption',
    'labels' => [],
    'card' => true,
    'positiveTone' => 'accent',
])

{{--
    Plan against actual, one row per category (the closed Month, Close step 1). Neutral rows
    divided by lines; only the deviation number is coloured. An ARIA table on a CSS grid with
    subgrid rows, so every column is as wide as its widest number in all rows (right-aligned,
    tabular). In a narrow container (under ~340px) the name takes its own line above the numbers;
    with a seven-digit amount anywhere that happens under ~480px, so names are never squeezed.

    rows: list of [
        'name' => string,
        'planned' => int,            minor units
        'actual' => int,
        'deviation' => ?int,         planned − actual when left out: + is money left in the budget,
                                     − is overspending (shown with "+" / "−"; − is danger,
                                     + takes positiveTone: 'accent' or 'ink'; 0 is muted)
        'note' => ?string,           one muted line under the name ("not paid", "over by 3 000 Ft")
        'noteTone' => ?string,       'danger' | 'warn' | 'muted' (default)
        'key' => string|int|null,    wire:key for the row
        'test' => ?string,           data-test on the row
    ]
    total: one more row in the same shape, semibold, after a line (optional).
    caption: the table's accessible name ("Plan vs. actual").
    labels: column headings, keys name / planned / actual / deviation
            (default Category / Plan / Actual / Diff.).
    card: true wraps it in a surface card (rounded-card bg-surface); false for use inside a card or sheet.
    after (slot): extra full-width rows before the total, e.g. the fixed items summary:
        <div role="row" class="col-span-full grid grid-cols-[minmax(0,1fr)_auto] gap-3 border-t border-line px-2.5 py-3 text-sm">
            <span role="rowheader">Fixed items · 4</span><span role="cell" class="num text-muted">…</span>
        </div>
--}}
@php
    $labels = array_merge([
        'name' => __('Category'),
        'planned' => __('Plan'),
        'actual' => __('Actual'),
        'deviation' => __('Diff.'),
    ], $labels);
    $signed = static function (int $amount): string {
        if ($amount === 0) {
            return '0';
        }

        return ($amount > 0 ? '+' : "\u{2212}").money_number(abs($amount));
    };
    $deviationTone = static fn (int $amount): string => match (true) {
        $amount < 0 => 'text-danger',
        $amount === 0 => 'text-muted',
        default => $positiveTone === 'ink' ? 'text-ink' : 'text-accent',
    };
    $noteTones = ['danger' => 'text-danger', 'warn' => 'text-warn', 'muted' => 'text-muted'];
    // Three seven-digit columns leave a name no room on a phone: stack earlier when any number is that long.
    $amounts = collect([...$rows, ...($total ? [$total] : [])])
        ->flatMap(fn (array $row): array => [$row['planned'], $row['actual'], $row['deviation'] ?? ($row['planned'] - $row['actual'])]);
    $long = $amounts->contains(fn ($amount): bool => mb_strlen(money_number(abs((int) $amount))) >= 9);
    $layout = $long
        ? ['table' => '@min-[480px]:grid-cols-[minmax(0,1fr)_repeat(3,minmax(3.5rem,max-content))]', 'name' => '@min-[480px]:col-span-1', 'header' => '@min-[480px]:not-sr-only @min-[480px]:text-left']
        : ['table' => '@min-[340px]:grid-cols-[minmax(0,1fr)_repeat(3,minmax(3.5rem,max-content))]', 'name' => '@min-[340px]:col-span-1', 'header' => '@min-[340px]:not-sr-only @min-[340px]:text-left'];
    $rowClass = 'col-span-full grid grid-cols-subgrid items-baseline gap-y-0.5 px-2.5';
    $nameClass = 'col-span-full min-w-0 '.$layout['name'];
@endphp

<div {{ $attributes->class(['@container', 'rounded-card bg-surface px-2 py-1.5' => $card]) }}>
    <div role="table" aria-label="{{ $caption }}" class="num grid grid-cols-3 gap-x-3 text-sm {{ $layout['table'] }}">
        <div role="row" class="{{ $rowClass }} pb-1.5 pt-2.5 text-xs font-semibold text-muted">
            <span role="columnheader" class="sr-only {{ $layout['header'] }}">{{ $labels['name'] }}</span>
            <span role="columnheader" class="text-right">{{ $labels['planned'] }}</span>
            <span role="columnheader" class="text-right">{{ $labels['actual'] }}</span>
            <span role="columnheader" class="text-right">{{ $labels['deviation'] }}</span>
        </div>
        @foreach ($rows as $row)
            @php $deviation = (int) ($row['deviation'] ?? ($row['planned'] - $row['actual'])); @endphp
            <div role="row" class="{{ $rowClass }} border-t border-line py-[11px]"
                 @isset($row['key']) wire:key="plan-actual-{{ $row['key'] }}" @endisset
                 @if (! empty($row['test'])) data-test="{{ $row['test'] }}" @endif>
                <span role="rowheader" class="{{ $nameClass }}">
                    <span class="block truncate text-[15px] text-ink">{{ $row['name'] }}</span>
                    @if (! empty($row['note']))
                        <span @class(['block text-xs leading-snug', $noteTones[$row['noteTone'] ?? 'muted'] ?? 'text-muted']) data-test="plan-actual-note">{{ $row['note'] }}</span>
                    @endif
                </span>
                <span role="cell" class="text-right text-muted">{{ money_number((int) $row['planned']) }}</span>
                <span role="cell" class="text-right text-ink">{{ money_number((int) $row['actual']) }}</span>
                <span role="cell" @class(['text-right font-semibold', $deviationTone($deviation)]) data-test="plan-actual-deviation">{{ $signed($deviation) }}</span>
            </div>
        @endforeach
        {{ $after ?? '' }}
        @if ($total)
            @php $totalDeviation = (int) ($total['deviation'] ?? ($total['planned'] - $total['actual'])); @endphp
            <div role="row" class="{{ $rowClass }} border-t border-line py-3 font-semibold" @if (! empty($total['test'])) data-test="{{ $total['test'] }}" @endif>
                <span role="rowheader" class="{{ $nameClass }} text-[15px]">{{ $total['name'] }}</span>
                <span role="cell" class="text-right font-normal text-muted">{{ money_number((int) $total['planned']) }}</span>
                <span role="cell" class="text-right font-normal text-ink">{{ money_number((int) $total['actual']) }}</span>
                <span role="cell" @class(['text-right', $deviationTone($totalDeviation)])>{{ $signed($totalDeviation) }}</span>
            </div>
        @endif
    </div>
</div>
