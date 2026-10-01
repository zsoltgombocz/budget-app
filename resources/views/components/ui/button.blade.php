@props(['variant' => 'primary', 'size' => 'lg', 'href' => null, 'icon' => null, 'type' => 'button'])

@php
    $variants = [
        'primary' => 'bg-accent text-accent-ink font-semibold disabled:bg-surface-3 disabled:text-faint',
        'secondary' => 'bg-surface-2 text-ink font-medium',
        'light' => 'bg-ink text-bg font-semibold',
        'ghost' => 'bg-transparent text-muted font-medium',
        'danger' => 'bg-danger/14 text-danger font-semibold',
        'outline' => 'bg-transparent border border-line text-ink font-medium',
    ];
    $sizes = [
        'lg' => 'h-14 rounded-btn px-5 text-[17px] gap-2',
        'md' => 'h-11 rounded-[14px] px-4 text-sm gap-2',
        'sm' => 'h-9 rounded-xl px-3.5 text-[13px] gap-1.5',
    ];
    $classes = 'inline-flex items-center justify-center whitespace-nowrap transition active:scale-[0.98] disabled:active:scale-100 '.($variants[$variant] ?? $variants['primary']).' '.($sizes[$size] ?? $sizes['lg']);
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon)<x-ui.icon :name="$icon" :size="$size === 'lg' ? 22 : 18" />@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>
        @if ($icon)<x-ui.icon :name="$icon" :size="$size === 'lg' ? 22 : 18" />@endif
        {{ $slot }}
    </button>
@endif
