<?php

use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * "Hogy működik?" (S18): five short cards instead of the 1.x tour. Every sentence matches the code
 * (MonthBudget, FixedLines, MarkLineDone, CloseMonth, Rewards).
 */
new #[Title('How it works')] class extends Component {}; ?>

@php
    $cards = [
        ['icon' => 'light_mode', 'title' => __('Left this month'), 'text' => __('The main number: this much you can still spend this month – fixed expenses and savings are already taken off. The daily allowance is that spread over the days left, today included. Spend less today and tomorrow’s allowance is bigger.')],
        ['icon' => 'add', 'title' => __('Recording'), 'text' => __('Type the amount, then pick what it was for. A day with no spending: tap “Ma nem költöttem”. Money on top of your income goes under “Kaptam pénzt” and counts at once.')],
        ['icon' => 'receipt_long', 'title' => __('Fixed expenses'), 'text' => __('What goes out every month is taken off in advance, you do not record it. With a day set I tell you on that morning; if you pay it by hand, an “Elintéztem” button shows. It changes no number.')],
        ['icon' => 'savings', 'title' => __('Savings and goals'), 'text' => __('A monthly savings line is a fixed expense too. On its day tap “Félretettem” and its goal grows. The app never moves money: you move it in your bank.')],
        ['icon' => 'task_alt', 'title' => __('Closing'), 'text' => __('At the end of the month one question: what happens to what is left – set aside, take it into the next month, or just note it. A month in the minus can be taken off the next one. A closing can be undone until the next one.')],
    ];
@endphp

<x-pages::settings.layout :heading="__('How it works')" :subheading="__('Five short cards')">
    <div class="flex flex-col gap-3" data-test="help-cards">
        @foreach ($cards as $card)
            <x-ui.card class="p-[18px]">
                <div class="flex items-center gap-3">
                    <x-ui.icon-tile :icon="$card['icon']" :size="36" />
                    <div class="text-[15px] font-semibold">{{ $card['title'] }}</div>
                    <span class="num ml-auto text-xs text-muted">{{ $loop->iteration }} / {{ $loop->count }}</span>
                </div>
                <p class="mt-2.5 text-[15px] leading-relaxed text-ink-2">{{ $card['text'] }}</p>
            </x-ui.card>
        @endforeach
    </div>
</x-pages::settings.layout>
