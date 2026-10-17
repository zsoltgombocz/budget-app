@props(['decimal' => false, 'symbol' => null])

{{--
    Numpad that slides up over a form sheet while an amount field is active. Uses active,
    activeLabel, display(), press() and amountSymbol() from the surrounding Alpine scope; Done
    (at the bottom, as on every numpad sheet) clears active. Focus moves into the pad when it
    opens and back to the field when it closes; Escape closes only the pad, not the sheet under it.
--}}
<div x-show="active" x-cloak class="absolute inset-0 z-10 flex flex-col justify-end" data-test="amount-pad"
     x-data="{
        padOpener: null,
        padFocus(tries) {
            if (! active || ! this.$refs.padPanel) return
            this.$refs.padPanel.focus({ preventScroll: true })
            if (document.activeElement !== this.$refs.padPanel && tries > 0) requestAnimationFrame(() => this.padFocus(tries - 1))
        },
     }"
     x-effect="if (active && ! padOpener) { padOpener = document.activeElement; requestAnimationFrame(() => padFocus(20)) } else if (! active && padOpener) { const opener = padOpener; padOpener = null; if (opener.isConnected) opener.focus({ preventScroll: true }) }"
     x-on:keydown.escape.window.capture="if (active && $el.offsetParent !== null) { $event.preventDefault(); $event.dialogHandled = true; active = null }">
    <div x-show="active" x-transition.opacity class="absolute inset-0 bg-black/40" x-on:click="active = null" aria-hidden="true"></div>
    <div x-show="active" x-ref="padPanel" tabindex="-1" role="group" :aria-label="activeLabel"
         x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
         x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
         class="relative rounded-t-[26px] bg-surface-2 px-4 pb-[calc(0.75rem+env(safe-area-inset-bottom))] pt-3 shadow-[0_-12px_40px_rgba(0,0,0,0.35)] outline-none">
        <div class="truncate text-center text-sm font-medium text-muted" x-text="activeLabel" aria-hidden="true"></div>
        <x-ui.amount-display display="active && display(active)" label-bind="activeLabel" symbol-bind="amountSymbol(active, {{ \Illuminate\Support\Js::from($symbol ?? user_currency()->symbol()) }})" size="lg" class="py-3" />
        <x-ui.numpad :decimal="$decimal" enter="active = null" />
        <x-ui.button x-on:click="active = null" class="mt-3 w-full" data-test="pad-done">{{ __('Done') }}</x-ui.button>
    </div>
</div>
