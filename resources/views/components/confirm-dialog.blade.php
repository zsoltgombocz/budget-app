{{--
    The app's own confirmation dialog (instead of the browser's confirm()). Open it from JS:
    if (await window.appConfirm({ title, body, confirm, danger: true })) { ... }
    Optional: highlight (one emphasised line, e.g. an exchange rate) and note (smaller muted
    text right under it), both shown above the body. The page behind it does not scroll.
--}}
<div x-data="{
        open: false,
        title: '',
        body: '',
        highlight: '',
        note: '',
        confirmLabel: '',
        danger: false,
        resolve: null,
        show(detail) {
            this.title = detail.title ?? ''
            this.body = detail.body ?? ''
            this.highlight = detail.highlight ?? ''
            this.note = detail.note ?? ''
            this.confirmLabel = detail.confirm ?? @js(__('OK'));
            this.danger = detail.danger ?? false
            this.resolve?.(false)
            this.resolve = detail.resolve
            this.open = true
        },
        answer(value) {
            this.open = false
            this.resolve?.(value)
            this.resolve = null
        },
     }"
     x-init="document.addEventListener('livewire:navigate', () => open && answer(false))"
     x-on:app-confirm.window="show($event.detail)">
    <div x-data="appDialog" x-effect="dialogSync(open)"
         x-on:keydown.escape.window="dialogEscape($event) && answer(false)"
         x-on:keydown.tab="dialogTrap($event)"
         x-show="open" x-cloak class="fixed inset-0 z-[60] flex overscroll-contain items-end justify-center sm:items-center" role="alertdialog" aria-modal="true" :aria-label="title" data-test="confirm-dialog">
        <div x-show="open" x-transition.opacity class="absolute inset-0 bg-black/60" x-on:click="answer(false)" aria-hidden="true"></div>
        <div x-show="open"
             x-transition:enter="transition duration-250 ease-out" x-transition:enter-start="translate-y-full opacity-0 sm:translate-y-4" x-transition:enter-end="translate-y-0 opacity-100"
             x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="translate-y-0 opacity-100" x-transition:leave-end="translate-y-full opacity-0 sm:translate-y-4"
             x-ref="dialogPanel" tabindex="-1"
             class="relative mx-auto w-full max-w-lg rounded-t-[30px] bg-surface outline-none px-5 pb-[calc(1.25rem+env(safe-area-inset-bottom))] pt-5 sm:mb-0 sm:max-w-sm sm:rounded-[26px] sm:pb-5">
            <div class="flex size-12 items-center justify-center rounded-2xl" :class="danger ? 'bg-danger/14 text-danger' : 'bg-accent/12 text-accent'">
                <x-ui.icon name="delete" :size="24" x-show="danger" />
                <x-ui.icon name="info" :size="24" x-show="! danger" />
            </div>
            <div class="mt-4 text-lg font-semibold leading-snug" x-text="title"></div>
            <div class="num mt-2 text-[15px] font-medium leading-snug text-ink" x-show="highlight" x-text="highlight" data-test="confirm-highlight"></div>
            <div class="num mt-0.5 text-[13px] leading-snug text-muted" x-show="note" x-text="note" data-test="confirm-note"></div>
            <p class="max-h-[55vh] overflow-y-auto overscroll-contain whitespace-pre-line text-[14px] leading-relaxed text-muted" :class="highlight || note ? 'mt-3' : 'mt-1.5'" x-show="body" x-text="body"></p>
            <div class="mt-5 grid grid-cols-2 gap-2.5">
                <x-ui.button variant="secondary" size="md" x-on:click="answer(false)" data-autofocus data-test="confirm-cancel">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button size="md" x-on:click="answer(true)" ::class="danger && '!bg-danger !text-white'" data-test="confirm-ok"><span x-text="confirmLabel"></span></x-ui.button>
            </div>
        </div>
    </div>
</div>
