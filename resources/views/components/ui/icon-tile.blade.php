@props(['icon', 'size' => 40, 'tone' => 'default', 'fill' => false])

@php
    $tones = [
        'default' => 'bg-surface-2 text-ink-2',
        'muted' => 'bg-surface-2 text-muted',
        'accent' => 'bg-accent/12 text-accent',
        'danger' => 'bg-danger/16 text-danger',
        'solid' => 'bg-accent text-accent-ink',
    ];
    $radius = $size >= 56 ? 18 : ($size >= 40 ? 14 : 12);
@endphp

<div {{ $attributes->class(['flex shrink-0 items-center justify-center overflow-hidden', $tones[$tone] ?? $tones['default']]) }} style="width: {{ $size }}px; height: {{ $size }}px; border-radius: {{ $radius }}px">
    <x-ui.icon :name="$icon" :size="(int) round($size * 0.55)" :fill="$fill" />
</div>
