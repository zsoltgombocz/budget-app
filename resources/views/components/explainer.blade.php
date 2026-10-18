@props(['topic', 'rows' => []])

{{--
    A small "i" button next to a figure that opens a short sheet: what the number is and how
    it is calculated. The texts live here, one entry per topic; `rows` carries the period's
    own numbers for the calculation, as [label, value] pairs (the last one is the result).
    Keep the texts in step with the code that computes the figure (named per entry). The title
    is the sheet's one-line header: short enough to fit at 320px, the full sentence goes in the lead.
--}}
@php
    $entries = [
        // PeriodCloser::preview()->leftover(): the same figure as the Month page and the closing.
        'leftover' => [
            'title' => __('Leftover'),
            'lead' => __('What would be left if the month ended today.'),
            'notes' => [
                __('Fixed items, transfers, loan installments and monthly savings count with their full planned amount; the variable categories only with what you have spent so far.'),
                __('So the part of the variable budgets you have not spent yet is still in this number: early in the period it is high and it goes down as you spend.'),
                __('Money added to the budget from a pocket counts as income, money put into a pocket is taken off; spending paid from a pocket does not count.'),
                __('A fixed item skipped this month counts as 0.'),
            ],
        ],
        // ForecastService::forecast()->expectedLeftover: variable categories with max(budget, spent).
        'if-spent' => [
            'title' => __('Expected leftover'),
            'lead' => __('What is left at the end of the period if you spend every variable budget in full.'),
            'notes' => [
                __('Every variable category counts with its full budget, or with what you have spent if that is more already.'),
                __('As long as you spend within your budgets, it stays put.'),
                __('Overspending and money put into a pocket lower it; money taken out of a pocket and a skipped fixed item raise it.'),
            ],
        ],
        // Month page: PeriodCloser::preview()->leftover() and ForecastService::deviation() for the badge next to it.
        'month-leftover' => [
            'title' => __('Leftover'),
            'lead' => __('Based on your spending so far: what you have not spent yet counts as leftover.'),
            'notes' => [
                __('The badge next to it compares the month with the plan, as if you spent every budget in full.'),
                __('Only going over a budget, a skipped fixed item or a pocket move changes this.'),
                __('Red means you went over a budget. Money you put into a pocket is your choice, so it shows without red.'),
            ],
        ],
        // ForecastService::dailyAllowance(): (variable budget + net pocket moves − variable spent) / remaining days incl. today, rounded down.
        'daily' => [
            'title' => __('You can still spend today'),
            'lead' => __('What is left of your variable budgets, spread evenly over the remaining days, today included.'),
            'notes' => [
                __('Today’s spending is already taken off, so the amount is recalculated after every entry. Fixed items are not in it.'),
                __('Money taken from a pocket into the budget raises it, money put into a pocket lowers it.'),
                __('If spending every budget in full would end the period in the red, it shows 0.'),
                __('It is rounded down, so the days together never get more than what is left.'),
            ],
        ],
    ];
    $entry = $entries[$topic];
@endphp

<div x-data="{ explain: false }" class="inline-flex align-middle">
    <button type="button" x-on:click="explain = true"
            class="focus-ring relative -m-2 ml-[-0.25rem] inline-flex size-9 items-center justify-center rounded-full text-muted transition before:absolute before:-inset-1 active:bg-ink/6"
            aria-label="{{ __('How is this calculated?') }}" data-test="explain-{{ $topic }}">
        <x-ui.icon name="info" :size="18" />
    </button>

    <x-ui.sheet show="explain" close="explain = false" :label="$entry['title']" :close-label="__('Got it')" :full="false" data-test="explainer-{{ $topic }}">
        <div class="whitespace-normal pt-2 text-left font-sans text-[15px] leading-relaxed text-ink-2">
            <p class="text-ink">{{ $entry['lead'] }}</p>

            @if ($rows !== [])
                <div class="num mt-4 rounded-[18px] bg-surface-2 px-4 py-2 text-[13px]" data-test="explainer-rows">
                    @foreach ($rows as [$label, $value])
                        <div @class(['flex justify-between gap-3 py-1.5', 'mt-1 border-t border-line pt-2.5 font-semibold text-ink' => $loop->last && ! $loop->first, 'text-muted' => ! $loop->last])>
                            <span>{{ $label }}</span><span class="shrink-0">{{ $value }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

            @foreach ($entry['notes'] as $note)
                <p class="mt-3 text-sm text-muted">{{ $note }}</p>
            @endforeach
        </div>
    </x-ui.sheet>
</div>
