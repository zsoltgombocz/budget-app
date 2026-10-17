@props(['display', 'label' => null, 'labelBind' => null, 'symbol' => null, 'symbolBind' => null, 'size' => 'xl'])

{{--
    The typed amount above a numpad: a polite live region, so a screen reader hears every key
    ("Monthly budget: 12 500 Ft"). Until the first key it is highlighted like selected text:
    that key replaces the old value (amountFields() amountFresh).
--}}
@php
    $classes = [
        'xl' => 'text-[52px] leading-none font-semibold tracking-[-0.04em]',
        'lg' => 'text-[44px] leading-none font-semibold tracking-[-0.04em]',
    ][$size];
    $symbolClasses = ['xl' => 'text-[22px] font-medium', 'lg' => 'text-xl font-medium'][$size];
@endphp
<div {{ $attributes->class(['flex justify-center']) }} role="status" aria-live="polite" aria-atomic="true" data-test="amount-display">
    @if ($labelBind)
        <span class="sr-only" x-text="({{ $labelBind }}) + ':'"></span>
    @elseif ($label)
        <span class="sr-only">{{ $label }}:</span>
    @endif
    <span class="num inline-flex max-w-full items-baseline gap-2 whitespace-nowrap rounded-xl px-2 py-1 transition-colors" :class="amountFresh && 'bg-accent/20'">
        <span class="{{ $classes }} truncate" x-text="{{ $display }}"></span>
        @if ($symbolBind)
            <span class="{{ $symbolClasses }} text-muted" x-text="{{ $symbolBind }}"></span>
        @else
            <span class="{{ $symbolClasses }} text-muted">{{ $symbol ?? user_currency()->symbol() }}</span>
        @endif
    </span>
</div>
