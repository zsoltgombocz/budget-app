@props(['icon', 'title', 'dashed' => null, 'compact' => false])

{{--
    The one empty-state pattern. The dashed outline is reserved for empty states.

    Default (a page or list with nothing in it yet): a centred 56px tile, title 18/600, the
    slot as the explanation, actions below (a tonal md button). Dashed by default.
    compact: inside a card or group ("No passkey yet", "No loans yet", "No results"): a 40px
    tile on the left, title 15/500 and the slot as one muted line; not dashed by default,
    actions (if any) go under the text.
    dashed: force the outline on or off.
--}}
@php $dashed ??= ! $compact; @endphp

@if ($compact)
    <div {{ $attributes->class(['flex items-start gap-3 py-3', 'rounded-group border border-dashed border-ink/14 px-4' => $dashed]) }} data-empty-state="compact">
        <x-ui.icon-tile :icon="$icon" tone="muted" />
        <div class="min-w-0 flex-1 self-center">
            <div class="text-[15px] font-medium leading-snug">{{ $title }}</div>
            @if ($slot->isNotEmpty())
                <div class="mt-0.5 text-[13px] leading-snug text-pretty text-muted">{{ $slot }}</div>
            @endif
            @isset($actions)
                <div class="mt-2.5 flex flex-wrap gap-2">{{ $actions }}</div>
            @endisset
        </div>
    </div>
@else
    <div {{ $attributes->class(['flex flex-col items-center gap-2.5 rounded-card px-6 pb-[22px] pt-7 text-center', 'border border-dashed border-ink/14' => $dashed]) }}>
        <x-ui.icon-tile :icon="$icon" :size="56" :tone="$dashed ? 'accent' : 'muted'" />
        <div class="mt-1 text-lg font-semibold tracking-[-0.01em]">{{ $title }}</div>
        <div class="text-sm leading-normal text-pretty text-muted">{{ $slot }}</div>
        @isset($actions)
            <div class="mt-2 flex gap-2">{{ $actions }}</div>
        @endisset
    </div>
@endif
