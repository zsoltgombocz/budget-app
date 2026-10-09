@props(['value', 'size' => 'md', 'tone' => null, 'signed' => false])

{{-- A number with the currency symbol set smaller and muted: "12 951 Ft". size="sm" takes the text size from its class (list rows). --}}
@php
    $value = (int) $value;
    $sizes = [
        'hero' => ['text-[58px] leading-none font-semibold tracking-[-0.045em]', 'text-2xl font-medium'],
        'xl' => ['text-[52px] leading-none font-semibold tracking-[-0.04em]', 'text-[22px] font-medium'],
        'lg' => ['text-[30px] font-semibold tracking-[-0.02em]', 'text-lg font-medium'],
        'md' => ['text-xl font-semibold', 'text-sm font-medium'],
        'sm' => ['', 'text-[0.8em] font-normal'],
    ][$size];
    $toneClass = match ($tone) {
        'accent' => 'text-accent-strong',
        'danger' => 'text-danger',
        'warn' => 'text-warn',
        'muted' => 'text-muted',
        default => null,
    };
@endphp

<span {{ $attributes->class(['num inline-flex items-baseline whitespace-nowrap', $size === 'sm' ? 'gap-1' : 'gap-2']) }}>
    <span @class([$sizes[0], $toneClass])>{{ $signed && $value > 0 ? '+' : '' }}{{ money_number($value) }}</span>
    <span @class([$sizes[1], 'text-muted'])>{{ user_currency()->symbol() }}</span>
</span>
