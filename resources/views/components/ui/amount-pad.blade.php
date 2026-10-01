@props(['decimal' => false])

{{--
    Numpad that slides up over a form sheet while an amount field is active. Uses active,
    activeLabel, display() and press() from the surrounding Alpine scope; "Done" clears active.
--}}
<div x-show="active" x-cloak class="absolute inset-0 z-10 flex flex-col justify-end" data-test="amount-pad">
    <div class="absolute inset-0 bg-black/40" x-on:click="active = null"></div>
    <div x-show="active"
         x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
         class="relative rounded-t-[26px] bg-surface-2 px-4 pb-[calc(1rem+env(safe-area-inset-bottom))] pt-3 shadow-[0_-12px_40px_rgba(0,0,0,0.35)]">
        <div class="flex items-center justify-between gap-3">
            <span class="truncate text-sm font-medium text-muted" x-text="activeLabel"></span>
            <button type="button" x-on:click="active = null" class="h-9 shrink-0 rounded-full bg-accent px-4 text-sm font-semibold text-accent-ink" data-test="pad-done">{{ __('Done') }}</button>
        </div>
        <div class="num truncate py-3 text-center text-[44px] font-semibold tracking-[-0.04em]" x-text="active && display(active)"></div>
        <x-ui.numpad :decimal="$decimal" />
    </div>
</div>
