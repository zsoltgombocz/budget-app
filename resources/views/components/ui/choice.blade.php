@props(['selected' => false])

{{-- Selectable pill/tile: green outline and tint when selected. --}}
<button type="button" {{ $attributes->class([
    'border font-medium transition active:scale-[0.97]',
    'border-accent bg-accent/14 text-accent' => $selected,
    'border-transparent bg-surface-2 text-ink-2' => ! $selected,
]) }}>{{ $slot }}</button>
