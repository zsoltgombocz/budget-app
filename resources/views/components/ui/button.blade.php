@props(['variant' => 'primary', 'size' => 'lg', 'href' => null, 'icon' => null, 'iconTrailing' => null, 'type' => 'button'])

{{--
    The app's button: a rounded rectangle (never a pill), as a <button> or, with href, an <a>.

    variant — pick by role, not by looks:
      primary       The one main action of a screen or sheet (Save, Next, Open the new month).
                    Always lg and full width, in a footer or action bar; never inside a card.
      tonal         The main action inside a card or callout (Done, Transferred, Record, Pay in).
      secondary     A neutral alternative (Cancel in a dialog, Withdraw, Open the plan, a header
                    action such as "+ Add"). On a surface-2 group it darkens to ink/6, in a
                    callout to ink/8, so it never disappears into its background.
      text          A quiet action next to a tonal one (Skip this month, Details, Hide, Not now,
                    Undo). No fill until hover/press.
      danger        The entry point of a destructive action (Delete account, Delete pocket).
      danger-solid  Only the final confirmation of a destructive action (confirm dialog, inline delete).
    Rule of thumb: per card at most one tonal + one text, or two secondary as an equal pair
    (Pay in / Withdraw). Never two filled buttons of different colours side by side.

    size:
      lg  56px, radius 18, 17px, icon 22: footers and action bars.
      md  44px, radius 14, 15px, icon 20: a button on its own row in a card, dialogs, sheet content.
      sm  36px, radius 12, 14px, icon 18: inline in cards and callouts; an invisible ::before keeps the touch target 44px.

    icon / iconTrailing: Material Symbols names before / after the label (e.g. icon="check",
    icon-trailing="arrow_forward"). For an icon-only button on narrow screens wrap the label in
    <span class="max-[360px]:sr-only">…</span>. Any other attribute (wire:click, data-test,
    class="w-full", disabled, wire:navigate) is passed through.
--}}
@php
    $variants = [
        // The focus ring takes the ink colour: around a green button a green or dark-green ring would not show (≥3:1 on both themes).
        'primary' => 'bg-accent text-accent-ink font-semibold disabled:bg-surface-3 disabled:text-muted focus-visible:outline-ink!',
        'tonal' => 'bg-accent/14 text-accent font-semibold disabled:bg-surface-2 disabled:text-muted',
        'secondary' => 'bg-surface-2 text-ink font-medium in-[.bg-surface-2]:bg-ink/6 in-data-callout:bg-ink/8 disabled:text-muted',
        'text' => 'bg-transparent text-ink-2 font-medium hover:bg-ink/6 active:bg-ink/6 disabled:text-muted disabled:hover:bg-transparent',
        'danger' => 'bg-danger/14 text-danger font-semibold disabled:opacity-50',
        'danger-solid' => 'bg-danger-solid text-danger-ink font-semibold disabled:opacity-50 focus-visible:outline-ink!',
    ];
    $sizes = [
        'lg' => 'h-14 rounded-btn text-[17px] gap-2',
        'md' => 'h-11 rounded-control text-[15px] gap-2',
        // 36px tall to sit inside cards; the invisible ::before stretches the touch target to 44px.
        'sm' => 'relative h-9 rounded-small text-sm gap-1.5 before:absolute before:inset-x-0 before:-inset-y-1',
    ];
    $size = isset($sizes[$size]) ? $size : 'lg';
    $variant = isset($variants[$variant]) ? $variant : 'primary';
    // A text button hugs its label; the filled ones get room around it.
    $padding = $variant === 'text'
        ? ['lg' => 'px-3', 'md' => 'px-3', 'sm' => 'px-2'][$size]
        : ['lg' => 'px-5', 'md' => 'px-4', 'sm' => 'px-3.5'][$size];
    $iconSize = ['lg' => 22, 'md' => 20, 'sm' => 18][$size];
    $classes = 'focus-ring inline-flex items-center justify-center whitespace-nowrap transition focus-visible:transition-none active:scale-[0.98] disabled:cursor-default disabled:active:scale-100 '.$variants[$variant].' '.$sizes[$size].' '.$padding;
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }} data-variant="{{ $variant }}">
        @if ($icon)<x-ui.icon :name="$icon" :size="$iconSize" />@endif
        {{ $slot }}
        @if ($iconTrailing)<x-ui.icon :name="$iconTrailing" :size="$iconSize" />@endif
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }} data-variant="{{ $variant }}">
        @if ($icon)<x-ui.icon :name="$icon" :size="$iconSize" />@endif
        {{ $slot }}
        @if ($iconTrailing)<x-ui.icon :name="$iconTrailing" :size="$iconSize" />@endif
    </button>
@endif
