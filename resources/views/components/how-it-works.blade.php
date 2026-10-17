@props(['open' => false, 'dismiss' => 'dismissIntro'])

{{--
    "How it works": four cards (plan, recording spending, the two numbers, closing) shown once after onboarding
    and reopened from Settings. Closing it in any way calls the Livewire `dismiss` method, which
    remembers it server-side.
--}}
@php
    $cards = [
        ['icon' => 'edit_note', 'title' => __('1. The plan'), 'body' => __('Your monthly income, fixed items and variable budgets. The leftover is calculated from these. You can change them any time on the Plan screen.')],
        ['icon' => 'add', 'title' => __('2. Recording spending'), 'body' => __('Fixed items are already in the plan, so you only write down the variable spending with the + button. If you did not spend anything, one tap tells the app.')],
        ['icon' => 'savings', 'title' => __('3. The two numbers'), 'body' => __('The big number: what would be left if the month ended today. The smaller one: what is left if you spend every budget in full.')],
        ['icon' => 'task_alt', 'title' => __('4. Closing the month'), 'body' => __('At the end of the period you close it: you see the plan next to what really happened and decide where the leftover goes. The app only keeps track, you move the money in your bank yourself.')],
    ];
@endphp

<div x-data="{
        intro: @js($open),
        page: 0,
        total: {{ count($cards) }},
        closeIntro() {
            this.intro = false
            this.page = 0
            $wire.{{ $dismiss }}()
            const url = new URL(window.location.href)
            if (url.searchParams.has('bemutato')) {
                url.searchParams.delete('bemutato')
                history.replaceState(history.state, '', url)
            }
        },
     }">
    <x-ui.sheet show="intro" close="closeIntro()" :label="__('How it works')" :close-label="__('Got it')" :full="false" data-test="how-it-works">
        @foreach ($cards as $index => $card)
            <div x-show="page === {{ $index }}" class="flex flex-col items-center px-4 pb-2 pt-6 text-center" data-test="intro-card">
                <x-ui.icon-tile :icon="$card['icon']" tone="accent" :size="56" />
                <h3 class="mt-4 text-xl font-semibold tracking-[-0.02em]">{{ $card['title'] }}</h3>
                <p class="mt-2 text-[15px] leading-relaxed text-pretty text-muted">{{ $card['body'] }}</p>
            </div>
        @endforeach
        <div class="mt-4 flex justify-center gap-1.5" aria-hidden="true">
            @foreach ($cards as $index => $card)
                <span class="h-1.5 rounded-full transition-all" :class="page === {{ $index }} ? 'w-5 bg-accent' : 'w-1.5 bg-ink/18'"></span>
            @endforeach
        </div>
        <x-slot:footer>
            <x-ui.button x-show="page < total - 1" x-on:click="page++" class="w-full" data-test="intro-next">{{ __('Next') }}</x-ui.button>
            <x-ui.button x-show="page === total - 1" x-on:click="closeIntro()" class="w-full" data-test="intro-done">{{ __('Got it') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.sheet>
</div>
