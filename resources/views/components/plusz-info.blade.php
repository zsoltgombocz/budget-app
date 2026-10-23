{{-- "Mi van a Pluszban?" (S27): what Plusz has now, what is coming, and the price. Only what the code does is listed under "now". --}}
@php
    $prices = config('budget.prices.plus');
    $huf = \App\Enums\Currency::HUF;
    $now = [
        ['title' => __('When will I be loan-free?'), 'text' => __('loan planner, with prepayment')],
        ['title' => __('Unlimited watched categories'), 'text' => __('not just two')],
    ];
    $soon = [__('Couple mode – a shared budget for two'), __('Bank statement import'), __('Spreading yearly costs, forecasts for several goals'), __('Analysis: months side by side, trends, spotting subscriptions')];
@endphp
<div {{ $attributes }}>
    <p class="text-[13px] font-semibold text-ink-2">{{ __('Everything in Alap, and now:') }}</p>
    <ul class="mt-2 grid gap-2.5">
        @foreach ($now as $item)
            <li class="flex items-start gap-2.5">
                <x-ui.icon name="check_circle" :size="20" class="mt-px text-accent" />
                <span><span class="block text-[15px] font-medium">{{ $item['title'] }}</span><span class="block text-xs text-muted">{{ $item['text'] }}</span></span>
            </li>
        @endforeach
    </ul>
    <div class="mt-3 rounded-card border border-dashed border-line px-3.5 py-3 text-muted">
        <p class="text-[13px] font-semibold">{{ __('Coming to Plusz') }}</p>
        <ul class="mt-1.5 grid gap-1 text-[13px]">
            @foreach ($soon as $item)
                <li class="flex items-start gap-2"><x-ui.icon name="schedule" :size="16" class="mt-0.5" />{{ $item }}</li>
            @endforeach
        </ul>
        <p class="mt-2 text-xs">{{ __('It arrives for subscribers when it is ready. Later: bank sync.') }}</p>
    </div>
    <p class="num mt-3 text-[13px] text-ink-2">{{ __(':month / month or :year / year (≈ :perMonth a month)', ['month' => money($prices['month'], $huf), 'year' => money($prices['year'], $huf), 'perMonth' => money(intdiv($prices['year'], 12), $huf)]) }}</p>
</div>
