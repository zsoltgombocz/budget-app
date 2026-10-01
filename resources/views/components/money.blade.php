@props(['amount', 'signed' => false])

@php($value = (int) $amount)

<span {{ $attributes->class(['tabular-nums whitespace-nowrap']) }}>{{ $signed && $value > 0 ? '+' : '' }}{{ money($value) }}</span>
