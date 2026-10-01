@props(['icon', 'href' => null, 'label', 'size' => 36])

@php $classes = 'flex shrink-0 items-center justify-center rounded-full bg-surface text-ink-2 transition active:scale-95'; @endphp
@if ($href)
    <a href="{{ $href }}" aria-label="{{ $label }}" {{ $attributes->class($classes) }} style="width: {{ $size }}px; height: {{ $size }}px"><x-ui.icon :name="$icon" :size="20" /></a>
@else
    <button type="button" aria-label="{{ $label }}" {{ $attributes->class($classes) }} style="width: {{ $size }}px; height: {{ $size }}px"><x-ui.icon :name="$icon" :size="20" /></button>
@endif
