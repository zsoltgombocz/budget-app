@props(['tabs' => true])

{{--
    Design toast. Livewire: $this->dispatch('app-toast', title: '…', subtitle: '…', undo: 'event-name', params: [...]);
    "Visszavonás" re-dispatches the undo event to Livewire with the params.
--}}
<div x-data="{
        toast: null,
        timer: null,
        key: 0,
        show(detail) {
            this.toast = detail
            this.key++
            clearTimeout(this.timer)
            this.timer = setTimeout(() => this.toast = null, 5000)
        },
        undo() {
            if (this.toast?.undo) Livewire.dispatch(this.toast.undo, this.toast.params ?? {})
            this.toast = null
        },
    }"
     x-on:app-toast.window="show($event.detail)"
     @class([
         'pointer-events-none fixed inset-x-3 z-40 mx-auto max-w-md',
         'bottom-[calc(98px+env(safe-area-inset-bottom))]' => $tabs,
         'bottom-[calc(24px+env(safe-area-inset-bottom))]' => ! $tabs,
     ])>
    <template x-if="toast">
        <div x-transition:enter="transition duration-200" x-transition:enter-start="translate-y-3 opacity-0"
             class="pointer-events-auto relative flex h-[58px] items-center gap-3 overflow-hidden rounded-btn bg-surface-3 pl-4 pr-2 text-ink shadow-[0_14px_34px_rgba(0,0,0,0.5)]"
             role="status" data-test="toast">
            <span class="ms ms-fill text-accent" style="font-size:22px;width:22px;height:22px" aria-hidden="true" x-text="toast.icon ?? 'check_circle'"></span>
            <div class="min-w-0 flex-1">
                <div class="num truncate text-sm font-medium" x-text="toast.title"></div>
                <div class="num truncate text-xs text-muted" x-show="toast.subtitle" x-text="toast.subtitle"></div>
            </div>
            <button type="button" x-show="toast.undo" x-on:click="undo()" class="h-[42px] rounded-xl px-3 text-sm font-semibold text-accent">{{ __('Undo') }}</button>
            <div :key="key" class="absolute bottom-0 left-0 h-0.5 bg-accent/60" x-init="$el.animate([{ width: '100%' }, { width: '0%' }], { duration: 5000, fill: 'forwards' })"></div>
        </div>
    </template>
</div>
