@props(['category'])

@php
    $colors = [
        'sky' => 'text-sky-500', 'amber' => 'text-amber-500', 'indigo' => 'text-indigo-500',
        'emerald' => 'text-emerald-500', 'orange' => 'text-orange-500', 'fuchsia' => 'text-fuchsia-500',
        'zinc' => 'text-zinc-500', 'teal' => 'text-teal-500', 'rose' => 'text-rose-500',
        'lime' => 'text-lime-500', 'violet' => 'text-violet-500', 'pink' => 'text-pink-500',
        'red' => 'text-red-500', 'blue' => 'text-blue-500', 'yellow' => 'text-yellow-500', 'cyan' => 'text-cyan-500',
    ];
    $icon = $category->icon ?: 'tag';
@endphp

<flux:icon :icon="$icon" {{ $attributes->class([$colors[$category->color] ?? 'text-zinc-500']) }} />
