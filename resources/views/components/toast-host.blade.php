@props(['tabs' => true])

{{--
    Design toast. Livewire: $this->dispatch('app-toast', title: '…', subtitle: '…', icon: 'delete', undo: 'event-name', params: [...]);
    One row: icon (20, filled), title 14/500 with an optional subtitle (12 muted, at most two
    lines) and, when the toast can be undone, an inline "Undo" text button on the right that
    re-dispatches the undo event to Livewire with the params. No close button: a tap anywhere on
    the toast dismisses it. A toast that can be undone stays 8 seconds (time to notice and react),
    any other 5; `duration` (ms) overrides it. A 2px bar shows the time left.
    icon colours: check_circle (the default) accent; error / warning danger; anything else
    (delete, undo, info, …) muted.
    Theme: surface-3 with a shadow in dark, inverse (dark bubble) in light: the --toast-* tokens in app.css.
    Position: right above the tab bar. The floating record button would sit on top of it there,
    so while a toast shows the button slides away (x-tab-bar listens for app-toast-visibility)
    and comes back when the toast goes; the toast keeps the full width on a 320px phone.
--}}
<div x-data="{
        toast: null,
        timer: null,
        key: 0,
        duration: 5000,
        show(detail) {
            this.toast = detail
            this.duration = detail.duration ?? (detail.undo ? 8000 : 5000)
            this.key++
            clearTimeout(this.timer)
            this.timer = setTimeout(() => this.dismiss(), this.duration)
        },
        undo() {
            if (this.toast?.undo) Livewire.dispatch(this.toast.undo, this.toast.params ?? {})
            this.dismiss()
        },
        dismiss() {
            clearTimeout(this.timer)
            this.toast = null
        },
        icon() {
            return this.toast?.icon ?? 'check_circle'
        },
        iconColour() {
            const icon = this.icon()
            if (icon === 'check_circle') return 'text-(--toast-accent)'
            if (icon === 'error' || icon === 'warning') return 'text-(--toast-danger)'

            return 'text-(--toast-muted)'
        },
        announcement() {
            if (! this.toast) return ''

            return [this.toast.title, this.toast.subtitle].filter(Boolean).map((text) => /[.!?…]$/.test(text) ? text : text + '.').join(' ')
        },
    }"
     x-on:app-toast.window="show($event.detail)"
     x-effect="window.dispatchEvent(new CustomEvent('app-toast-visibility', { detail: toast !== null }))"
     @class([
         'pointer-events-none fixed inset-x-3 z-40 mx-auto max-w-md',
         'bottom-[calc(var(--tabbar-h)+12px+env(safe-area-inset-bottom))]' => $tabs,
         'bottom-[calc(24px+env(safe-area-inset-bottom))]' => ! $tabs,
     ])>
    {{-- The live region stays in the DOM so screen readers announce what appears in it. --}}
    <div class="sr-only" role="status" aria-live="polite" x-text="announcement()" data-test="toast-status"></div>
    <template x-if="toast">
        <div x-transition:enter="transition duration-200" x-transition:enter-start="translate-y-3 opacity-0"
             x-on:click="dismiss()"
             class="pointer-events-auto relative flex min-h-[52px] cursor-pointer items-center gap-3 overflow-hidden rounded-group bg-(--toast-bg) py-2.5 pl-4 text-(--toast-ink) shadow-(--toast-shadow)"
             :class="toast.undo ? 'pr-1.5' : 'pr-4'"
             data-test="toast">
            <span class="ms ms-fill shrink-0" :class="[iconColour(), toast.subtitle ? 'self-start mt-px' : 'self-center']" style="font-size:20px;width:20px;height:20px" aria-hidden="true" x-text="icon()" data-test="toast-icon"></span>
            <div class="min-w-0 flex-1">
                <div class="num line-clamp-2 text-sm font-medium leading-snug text-pretty" x-text="toast.title" data-test="toast-title"></div>
                <div class="num mt-0.5 line-clamp-2 text-xs leading-snug text-pretty text-(--toast-muted)" x-show="toast.subtitle" x-text="toast.subtitle" data-test="toast-subtitle"></div>
            </div>
            <button type="button" x-show="toast.undo" x-on:click.stop="undo()"
                    class="focus-ring -my-1.5 h-11 shrink-0 whitespace-nowrap rounded-control px-3 text-sm font-semibold text-(--toast-accent) hover:bg-white/8 active:bg-white/8"
                    data-test="toast-undo">{{ __('Undo') }}</button>
            <div :key="key" class="absolute bottom-0 left-0 h-0.5 bg-(--toast-accent)/60" x-init="$el.animate([{ width: '100%' }, { width: '0%' }], { duration, fill: 'forwards' })"></div>
        </div>
    </template>
</div>
