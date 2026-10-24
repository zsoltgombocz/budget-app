@props(['key'])

{{-- Full screen, once per milestone: the badge pops, rays and a little confetti. "Szuper" shows the next one, if any. --}}
@php
    $milestone = \App\Services\Rewards::milestones()[$key] ?? null;
    $colors = ['var(--app-accent)', 'var(--app-warn)', 'var(--app-ink)'];
@endphp

@if ($milestone)
    <div class="fixed inset-0 z-[60] flex flex-col overflow-hidden bg-bg" role="dialog" aria-modal="true" aria-labelledby="milestone-title" wire:key="celebration-{{ $key }}" data-test="milestone-celebration">
        @foreach ([40, 78, 120, 160, 205, 246, 288, 330, 356, 18] as $index => $left)
            <span class="celebrate-bit" style="left: {{ round($left / 390 * 100, 1) }}%; background: {{ $colors[$index % 3] }}; animation-delay: {{ 250 + ($index * 37 % 300) }}ms"></span>
        @endforeach

        <div class="flex flex-1 items-center justify-center">
            <div class="relative flex size-[300px] items-center justify-center">
                <svg class="celebrate-rays absolute inset-0" viewBox="0 0 300 300" aria-hidden="true">
                    <g stroke="var(--app-warn)" stroke-width="6" stroke-linecap="round" stroke-opacity=".5">
                        <line x1="150" y1="22" x2="150" y2="62" /><line x1="150" y1="238" x2="150" y2="278" />
                        <line x1="22" y1="150" x2="62" y2="150" /><line x1="238" y1="150" x2="278" y2="150" />
                        <line x1="59" y1="59" x2="87" y2="87" /><line x1="213" y1="213" x2="241" y2="241" />
                        <line x1="241" y1="59" x2="213" y2="87" /><line x1="59" y1="241" x2="87" y2="213" />
                    </g>
                    <g fill="var(--app-accent)"><circle cx="96" cy="34" r="4" /><circle cx="262" cy="104" r="5" /><circle cx="40" cy="196" r="4" /><circle cx="206" cy="270" r="4" /></g>
                </svg>
                <span class="celebrate-pop flex size-[148px] items-center justify-center rounded-full bg-warn text-[#2B1A05] shadow-[0_0_0_10px_var(--app-warn-tint)]">
                    <x-ui.icon :name="$milestone['icon']" :size="72" :fill="true" />
                </span>
            </div>
        </div>

        <div class="px-6 text-center">
            <div class="celebrate-up text-sm font-semibold text-warn" style="animation-delay: 500ms">{{ __('Milestone') }}</div>
            <h1 id="milestone-title" class="celebrate-up mt-1.5 text-[34px] font-extrabold tracking-[-0.03em]" style="animation-delay: 560ms">{{ $milestone['title'] }}</h1>
            <p class="celebrate-up mt-2.5 text-base text-ink-2" style="animation-delay: 620ms">{{ $milestone['text'] }}</p>
        </div>

        <div class="celebrate-up px-4 pt-10 pb-[calc(2rem+env(safe-area-inset-bottom))]" style="animation-delay: 700ms">
            <x-ui.button class="w-full" wire:click="nextCelebration" data-test="celebration-done">{{ __('Great') }}</x-ui.button>
        </div>
    </div>
@endif
