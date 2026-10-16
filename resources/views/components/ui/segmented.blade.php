@props(['model', 'options'])

{{-- Segmented control bound to the Alpine expression `model`; options is value => label. A radio group for screen readers, 44px tall; each 38px segment reaches into the padding for a 44px touch target. --}}
<div role="radiogroup" {{ $attributes->class(['grid gap-[3px] rounded-xl bg-bg p-[3px]']) }} style="grid-template-columns: repeat({{ count($options) }}, minmax(0, 1fr))">
    @foreach ($options as $value => $label)
        <button type="button" role="radio" x-on:click="{{ $model }} = @js($value)" :aria-checked="{{ $model }} === @js($value) ? 'true' : 'false'" :tabindex="{{ $model }} === @js($value) ? 0 : -1"
                x-on:keydown.right.prevent="$el.nextElementSibling?.click(); $nextTick(() => $el.nextElementSibling?.focus())"
                x-on:keydown.left.prevent="$el.previousElementSibling?.click(); $nextTick(() => $el.previousElementSibling?.focus())"
                class="focus-ring relative min-h-[38px] rounded-[9px] px-1 py-1.5 before:absolute before:inset-x-0 before:-inset-y-[3px] text-[13px] font-medium leading-tight transition-colors" :class="{{ $model }} === @js($value) ? 'bg-surface-3 text-ink' : 'text-muted'" data-test="segment-{{ $value }}">{{ $label }}</button>
    @endforeach
</div>
