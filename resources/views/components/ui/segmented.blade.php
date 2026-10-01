@props(['model', 'options'])

{{-- Segmented control bound to the Alpine expression `model`; options is value => label. --}}
<div {{ $attributes->class(['grid gap-[3px] rounded-xl bg-bg p-[3px]']) }} style="grid-template-columns: repeat({{ count($options) }}, minmax(0, 1fr))">
    @foreach ($options as $value => $label)
        <button type="button" x-on:click="{{ $model }} = @js($value)" class="min-h-9 rounded-[9px] px-1 py-1.5 text-[13px] font-medium leading-tight transition-colors" :class="{{ $model }} === @js($value) ? 'bg-surface-3 text-ink' : 'text-muted'" data-test="segment-{{ $value }}">{{ $label }}</button>
    @endforeach
</div>
