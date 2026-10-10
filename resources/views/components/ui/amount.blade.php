@props(['value' => 0, 'bind' => null, 'of' => null, 'symbol' => null, 'bindSymbol' => null, 'currency' => null, 'size' => 'md', 'tone' => null, 'signed' => false])

{{--
    An amount as a value: the number with the currency symbol set smaller and muted, tabular
    digits ("12 951 Ft"). size="sm" takes the text size from its class (list rows).
    of: a goal shown after the number ("450 000 / 500 000 Ft"). bind: an Alpine expression
    for the (already formatted) number instead of value; symbol / bindSymbol override the
    currency symbol (e.g. "%"); currency: another than the user's (onboarding). Without a tone the number inherits the text colour.
--}}
@php
    $value = (int) $value;
    $currency ??= user_currency();
    $sizes = [
        'display' => ['text-[60px] leading-[1.05] font-semibold tracking-[-0.045em] short:text-[44px]', 'text-[26px] font-medium short:text-xl'],
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
    @if ($bind)
        <span @class([$sizes[0], $toneClass]) x-text="{{ $bind }}"></span>
    @else
        <span @class([$sizes[0], $toneClass])>{{ $signed && $value > 0 ? '+' : '' }}{{ money_number($value, $currency) }}</span>
    @endif
    @if ($of !== null)
        <span @class([$size === 'sm' ? 'font-normal' : $sizes[1], 'text-muted'])>/ {{ money_number((int) $of, $currency) }}</span>
    @endif
    @if ($bindSymbol)
        <span @class([$sizes[1], 'text-muted']) x-text="{{ $bindSymbol }}"></span>
    @else
        <span @class([$sizes[1], 'text-muted'])>{{ $symbol ?? $currency->symbol() }}</span>
    @endif
</span>
