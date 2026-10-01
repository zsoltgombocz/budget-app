@props(['amount', 'signed' => false])

@php $value = (int) $amount; @endphp
<span {{ $attributes->class(['tabular-nums whitespace-nowrap']) }}>{{ $signed && $value > 0 ? '+' : '' }}{{ money($value) }}</span>
