@props(['label', 'value', 'pick', 'hint' => null, 'error' => null, 'clearLabel' => null])

{{--
    A day of the month (1–31) picked from a grid, like the payday, instead of typed on the numpad.
    value: Alpine expression of the current day ('' or null when none); pick: Alpine statement run
    with `day` (a number, or null for "no set day"). The row opens the grid under itself; without a
    day it reads a muted "Not set".
--}}
<div x-data="{ dayOpen: false }" {{ $attributes }}>
    <button type="button" x-on:click="dayOpen = ! dayOpen" :aria-expanded="dayOpen ? 'true' : 'false'"
            class="focus-ring flex min-h-[52px] w-full items-center justify-between gap-3 rounded-[inherit] px-4 py-2.5 text-left" data-test="day-field">
        <span class="min-w-0">
            <span class="block text-[15px]">{{ $label }}</span>
            @if ($hint)<span class="mt-0.5 block text-xs leading-snug text-muted">{{ $hint }}</span>@endif
            @if ($error)<span class="mt-0.5 block text-xs text-danger" x-show="errors[@js($error)]" x-text="errors[@js($error)]"></span>@endif
        </span>
        <span class="flex shrink-0 items-center gap-1">
            <span class="num" :class="({{ $value }}) ? 'text-[17px] font-semibold text-accent' : 'text-[15px] text-muted'" x-text="({{ $value }}) ? ({{ $value }}) + '.' : @js(__('Not set'))" data-test="day-value"></span>
            <x-ui.icon name="expand_more" :size="18" class="text-faint transition" ::class="dayOpen && 'rotate-180'" />
        </span>
    </button>
    <div x-show="dayOpen" x-cloak class="px-3 pb-3">
        <div class="grid grid-cols-7 gap-1.5" role="radiogroup" aria-label="{{ $label }}" data-test="day-grid">
            @for ($day = 1; $day <= 31; $day++)
                <button type="button" role="radio" :aria-checked="String({{ $value }} ?? '') === '{{ $day }}' ? 'true' : 'false'"
                        x-on:click="(day => { {{ $pick }} })({{ $day }}); dayOpen = false"
                        class="focus-ring num flex min-h-11 items-center justify-center rounded-xl border text-sm font-medium"
                        :class="String({{ $value }} ?? '') === '{{ $day }}' ? 'border-accent bg-accent/14 text-accent' : 'border-transparent bg-surface-3 text-ink-2'"
                        data-day="{{ $day }}">{{ $day }}</button>
            @endfor
        </div>
        <div class="mt-2 flex items-center justify-between gap-3">
            <p class="text-xs leading-snug text-muted">{{ __('If the month is shorter, the last day counts.') }}</p>
            <button type="button" x-on:click="(day => { {{ $pick }} })(null); dayOpen = false" class="focus-ring min-h-11 shrink-0 rounded-xl px-2 text-[13px] font-medium text-accent-strong" data-test="day-clear">{{ $clearLabel ?? __('No set day') }}</button>
        </div>
    </div>
</div>
