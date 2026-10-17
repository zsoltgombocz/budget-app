@props(['model', 'options'])

{{--
    Segmented control bound to the Alpine expression `model`; options is value => label.
    Only for 2–3 SHORT options whose labels fit on one line at 320px ("Planned / Typical /
    Expensive"); at most one per sheet or screen. A label that needs two lines means the wrong
    control: use a radio list (icon + title + one line of explanation) instead, and for
    switching between views of one thing use x-ui.tabs.
    A radio group for screen readers, 44px tall; each 38px segment reaches into the padding for
    a 44px touch target. Track radius 14 (control), segment 10.
--}}
<div role="radiogroup" {{ $attributes->class(['grid gap-[3px] rounded-control bg-bg p-[3px]']) }} style="grid-template-columns: repeat({{ count($options) }}, minmax(0, 1fr))">
    @foreach ($options as $value => $label)
        <button type="button" role="radio" x-on:click="{{ $model }} = @js($value)" :aria-checked="{{ $model }} === @js($value) ? 'true' : 'false'" :tabindex="{{ $model }} === @js($value) ? 0 : -1"
                x-on:keydown.right.prevent="$el.nextElementSibling?.click(); $nextTick(() => $el.nextElementSibling?.focus())"
                x-on:keydown.left.prevent="$el.previousElementSibling?.click(); $nextTick(() => $el.previousElementSibling?.focus())"
                class="focus-ring relative min-h-[38px] rounded-[10px] px-1 py-1.5 before:absolute before:inset-x-0 before:-inset-y-[3px] text-[13px] font-medium leading-tight transition-colors" :class="{{ $model }} === @js($value) ? 'bg-surface-3 text-ink' : 'text-muted'" data-test="segment-{{ $value }}">{{ $label }}</button>
    @endforeach
</div>
