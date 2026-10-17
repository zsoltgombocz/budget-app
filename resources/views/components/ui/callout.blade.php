@props(['tone' => 'neutral', 'icon' => null, 'title' => null])

{{--
    A message block inside the page flow (not a card): an icon, an optional title, the text and
    optional actions. No border; the tone tints the background.

    tone:
      neutral  surface-2 with a muted icon: information, previews ("A month with your numbers"),
               "Pick up where you left off". The default; use it whenever nothing is wrong.
      warn     Something needs attention soon (the period has not ended yet).
      danger   Something is wrong now (over the plan).
      success  A positive state the user can act on (prepayment can start).
    At most ONE coloured (warn/danger/success) callout per screen.

    icon: a Material Symbols name; defaults to info / warning / error / check_circle by tone.
    title: 15/600 ink (optional). Slot: the text, 13 muted, keep it to three lines.
    actions (slot): sm buttons, e.g. <x-ui.button variant="tonal" size="sm">Done</x-ui.button>
    plus a variant="text" one. In a danger callout use secondary instead of tonal: inside a
    callout it turns ink/8 by itself, so the red background keeps one meaning.
--}}
@php
    $tones = [
        'neutral' => ['bg-surface-2', 'text-muted', 'info'],
        'warn' => ['bg-warn/10', 'text-warn', 'warning'],
        'danger' => ['bg-danger/10', 'text-danger', 'error'],
        'success' => ['bg-accent/10', 'text-accent', 'check_circle'],
    ];
    $tone = isset($tones[$tone]) ? $tone : 'neutral';
    [$background, $iconColour, $defaultIcon] = $tones[$tone];
@endphp

<div {{ $attributes->class(['grid grid-cols-[20px_minmax(0,1fr)] gap-x-3 rounded-callout px-4 py-3.5', $background]) }} data-callout="{{ $tone }}">
    <x-ui.icon :name="$icon ?? $defaultIcon" :size="20" @class(['mt-px', $iconColour]) />
    <div class="min-w-0">
        @if ($title)
            <div class="text-[15px] font-semibold leading-snug text-pretty text-ink">{{ $title }}</div>
        @endif
        @if ($slot->isNotEmpty())
            <div @class(['text-[13px] leading-snug text-pretty text-muted', 'mt-0.5' => $title])>{{ $slot }}</div>
        @endif
    </div>
    @isset($actions)
        <div class="col-start-2 mt-3 flex flex-wrap items-center gap-1">{{ $actions }}</div>
    @endisset
</div>
