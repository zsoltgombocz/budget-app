@props(['value' => 0, 'tone' => 'accent', 'height' => 6])

@php
    $percent = max(0, min(100, $value * 100));
    $color = ['accent' => 'bg-accent', 'warn' => 'bg-warn', 'danger' => 'bg-danger', 'ink' => 'bg-ink-2'][$tone] ?? 'bg-accent';
@endphp

<div {{ $attributes->class(['overflow-hidden rounded-full bg-track']) }} style="height: {{ $height }}px" role="progressbar" aria-valuenow="{{ (int) round($percent) }}" aria-valuemin="0" aria-valuemax="100">
    <div class="h-full rounded-full {{ $color }} transition-[width] duration-300" style="width: {{ $percent }}%"></div>
</div>
