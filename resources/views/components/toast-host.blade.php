@props(['tabs' => true])

{{--
    Design toast. Livewire: $this->dispatch('app-toast', title: '…', subtitle: '…', undo: 'event-name', params: [...]);
    "Visszavonás" re-dispatches the undo event to Livewire with the params. A toast that can be
    undone stays 8 seconds (time to notice and react), any other 5; `duration` (ms) overrides it.
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
            this.timer = setTimeout(() => this.toast = null, this.duration)
        },
        undo() {
            if (this.toast?.undo) Livewire.dispatch(this.toast.undo, this.toast.params ?? {})
            this.dismiss()
        },
        dismiss() {
            clearTimeout(this.timer)
            this.toast = null
        },
        announcement() {
            if (! this.toast) return ''

            return [this.toast.title, this.toast.subtitle].filter(Boolean).map((text) => /[.!?…]$/.test(text) ? text : text + '.').join(' ')
        },
    }"
     x-on:app-toast.window="show($event.detail)"
     @class([
         'pointer-events-none fixed inset-x-3 z-40 mx-auto max-w-md',
         'bottom-[calc(166px+env(safe-area-inset-bottom))]' => $tabs,
         'bottom-[calc(24px+env(safe-area-inset-bottom))]' => ! $tabs,
     ])>
    {{-- The live region stays in the DOM so screen readers announce what appears in it. --}}
    <div class="sr-only" role="status" aria-live="polite" x-text="announcement()" data-test="toast-status"></div>
    <template x-if="toast">
        <div x-transition:enter="transition duration-200" x-transition:enter-start="translate-y-3 opacity-0"
             x-on:click="dismiss()"
             class="pointer-events-auto relative grid min-h-[58px] cursor-pointer grid-cols-[22px_minmax(0,1fr)_auto] items-center gap-x-3 overflow-hidden rounded-btn bg-surface-3 py-2 pl-4 pr-2 text-ink shadow-[0_14px_34px_rgba(0,0,0,0.5)]"
             data-test="toast">
            <span class="ms ms-fill text-accent" style="font-size:22px;width:22px;height:22px" aria-hidden="true" x-text="toast.icon ?? 'check_circle'"></span>
            <div class="min-w-0 py-1">
                <div class="num line-clamp-2 text-sm font-medium leading-snug text-pretty" x-text="toast.title" data-test="toast-title"></div>
                <div class="num line-clamp-3 text-xs leading-snug text-pretty text-muted" x-show="toast.subtitle" x-text="toast.subtitle"></div>
            </div>
            <button type="button" x-show="toast.undo" x-on:click.stop="dismiss()" class="focus-ring flex size-11 shrink-0 items-center justify-center rounded-xl text-muted" aria-label="{{ __('Close') }}" data-test="toast-close"><x-ui.icon name="close" :size="20" /></button>
            {{-- The action gets its own row so the message keeps the full width on a narrow phone. --}}
            <button type="button" x-show="toast.undo" x-on:click.stop="undo()" class="focus-ring col-span-2 col-start-2 -my-1 h-11 -ml-2 justify-self-start whitespace-nowrap rounded-xl px-2 text-sm font-semibold text-accent-strong">{{ __('Undo') }}</button>
            <div :key="key" class="absolute bottom-0 left-0 h-0.5 bg-accent/60" x-init="$el.animate([{ width: '100%' }, { width: '0%' }], { duration, fill: 'forwards' })"></div>
        </div>
    </template>
</div>
