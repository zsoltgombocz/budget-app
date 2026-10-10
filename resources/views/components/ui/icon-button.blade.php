@props(['icon', 'href' => null, 'label', 'size' => 36])

{{-- Round icon button; below 44px the invisible ::before widens the touch target. --}}
@php $classes = 'focus-ring relative flex shrink-0 items-center justify-center rounded-full bg-surface text-ink-2 transition active:scale-95'.($size < 44 ? ' before:absolute before:-inset-1' : ''); @endphp
@if ($href)
    <a href="{{ $href }}" aria-label="{{ $label }}" {{ $attributes->class($classes) }} style="width: {{ $size }}px; height: {{ $size }}px"><x-ui.icon :name="$icon" :size="20" /></a>
@else
    <button type="button" aria-label="{{ $label }}" {{ $attributes->class($classes) }} style="width: {{ $size }}px; height: {{ $size }}px"><x-ui.icon :name="$icon" :size="20" /></button>
@endif
