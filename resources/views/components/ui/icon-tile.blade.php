@props(['icon', 'size' => 40, 'tone' => 'default', 'fill' => false])

{{--
    A Material Symbols icon on a tinted square: the left side of a list row (40, the default),
    the head of an empty state or dialog (56), small rows (36).
    tone:
      default  surface-2 + ink-2: everywhere, unless one of the others says something. Inside a
               surface-2 group (a sheet's list) it darkens to ink/6, like the secondary button.
      muted    surface-2 + muted: inactive, archived, decoration.
      accent   only for money coming in (salary, pocket) and confirmations (feedback sent).
      warn     a due or overdue fixed item, a budget past 80%.
      danger   overspending, destructive actions.
      solid    accent fill: a primary "add" tile.
    Radius follows the size: 56+ → group (18), 40+ → control (14), smaller → small (12).
--}}
@php
    $tones = [
        'default' => 'bg-surface-2 text-ink-2 in-[.bg-surface-2]:bg-ink/6',
        'muted' => 'bg-surface-2 text-muted in-[.bg-surface-2]:bg-ink/6',
        'accent' => 'bg-accent/12 text-accent',
        'warn' => 'bg-warn/14 text-warn',
        'danger' => 'bg-danger/14 text-danger',
        'solid' => 'bg-accent text-accent-ink',
    ];
    $radius = $size >= 56 ? 'rounded-group' : ($size >= 40 ? 'rounded-control' : 'rounded-small');
@endphp

<div {{ $attributes->class(['flex shrink-0 items-center justify-center overflow-hidden', $radius, $tones[$tone] ?? $tones['default']]) }} style="width: {{ $size }}px; height: {{ $size }}px">
    <x-ui.icon :name="$icon" :size="(int) round($size * 0.55)" :fill="$fill" />
</div>
