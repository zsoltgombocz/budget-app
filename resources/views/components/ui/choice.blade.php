@props(['selected' => false])

{{-- Selectable pill/tile: green outline and tint when selected; aria-pressed tells screen readers. At least 44px tall. --}}
<button type="button" aria-pressed="{{ $selected ? 'true' : 'false' }}" {{ $attributes->class([
    'focus-ring min-h-11 border font-medium transition active:scale-[0.97]',
    'border-accent bg-accent/14 text-accent' => $selected,
    'border-transparent bg-surface-2 text-ink-2' => ! $selected,
]) }}>{{ $slot }}</button>
