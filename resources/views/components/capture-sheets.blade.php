@props(['categories'])

{{--
    Sheets of the capture inbox (Alpine scope from ⚡capture-inbox): change the category or
    delete a captured spending, and type the base-currency amount of a foreign payment.
--}}
@php $currency = user_currency(); @endphp
{{-- Change category or delete --}}
<div x-show="selected" x-cloak class="fixed inset-0 z-50" role="dialog" aria-modal="true">
    <div x-show="selected" x-transition.opacity class="absolute inset-0 bg-black/55" x-on:click="selected = null"></div>
    <div x-show="selected" x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0" x-transition:leave="transition duration-200 ease-in" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
         class="absolute inset-x-0 bottom-0 mx-auto max-w-lg rounded-t-[30px] bg-surface px-4 pb-[calc(1.5rem+env(safe-area-inset-bottom))] pt-2">
        <div class="mx-auto h-[5px] w-9 rounded-full bg-ink/18"></div>
        <div class="py-4 text-center">
            <div class="text-sm text-muted" x-text="selected?.title"></div>
            <div class="num mt-1 text-[40px] font-semibold tracking-[-0.03em]" x-text="selected?.amount"></div>
            <div class="mt-1 flex items-center justify-center gap-1 text-xs text-muted"><x-ui.icon name="bolt" :size="14" class="text-accent" />{{ __('Captured automatically') }}</div>
        </div>
        <div class="px-1.5 pb-2 text-xs font-semibold uppercase tracking-[0.06em] text-muted">{{ __('Category') }}</div>
        <div class="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4 pb-3" data-test="recategorize">
            @foreach ($categories as $category)
                <button type="button" x-on:click="$wire.recategorize(selected.id, {{ $category['id'] }}); selected = null"
                        class="flex h-[70px] min-w-[calc((100%-32px)/5)] flex-1 flex-col items-center justify-center gap-1.5 rounded-btn border-[1.5px] px-1 transition active:scale-95"
                        :class="selected?.categoryId === {{ $category['id'] }} ? 'border-accent bg-accent/14 text-accent' : 'border-transparent bg-surface-2 text-ink-2'">
                    <x-ui.icon :name="$category['icon']" :size="24" />
                    <span class="max-w-full truncate text-xs font-medium">{{ $category['name'] }}</span>
                </button>
            @endforeach
        </div>
        <x-ui.button variant="danger" icon="delete" class="w-full" x-on:click="$wire.delete(selected.id); selected = null" data-test="delete-capture">{{ __('Delete entry') }}</x-ui.button>
        <x-ui.button variant="ghost" class="mt-1 w-full" x-on:click="selected = null">{{ __('Close') }}</x-ui.button>
    </div>
</div>

{{-- Base-currency amount of a foreign-currency payment --}}
<div x-show="pad" x-cloak class="fixed inset-0 z-50" role="dialog" aria-modal="true" data-test="capture-pad">
    <div x-show="pad" x-transition.opacity class="absolute inset-0 bg-black/55" x-on:click="pad = null"></div>
    <div x-show="pad" x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0" x-transition:leave="transition duration-200 ease-in" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
         class="absolute inset-x-0 bottom-0 mx-auto max-w-lg rounded-t-[30px] bg-surface px-4 pb-[calc(1.25rem+env(safe-area-inset-bottom))] pt-2">
        <div class="mx-auto h-[5px] w-9 rounded-full bg-ink/18"></div>
        <div class="mt-1 grid h-11 grid-cols-[72px_1fr_72px] items-center">
            <button type="button" class="text-left text-[15px] text-muted" x-on:click="pad = null">{{ __('Cancel') }}</button>
            <div class="truncate text-center text-base font-semibold" x-text="pad?.label"></div>
            <span></span>
        </div>
        <div class="pb-1 pt-2 text-center">
            <div class="num flex items-baseline justify-center gap-2">
                <span class="text-[52px] font-semibold leading-[1.05] tracking-[-0.045em]" :class="digits ? 'text-ink' : 'text-faint'" x-text="format.format(parseInt(digits || '0', 10))"></span>
                <span class="text-[22px] font-medium text-muted">{{ $currency->symbol() }}</span>
            </div>
            <div class="mt-1.5 text-[13px] text-muted">{{ __('How much was taken from your account, as on your bank statement.') }}</div>
        </div>
        <x-ui.numpad class="mt-3" />
        <button type="button" x-on:click="savePad()" :disabled="! digits"
                class="mt-3 h-14 w-full rounded-btn text-[17px] font-semibold transition active:scale-[0.98]"
                :class="digits ? 'bg-accent text-accent-ink' : 'bg-surface-3 text-zinc-500'" data-test="save-capture-amount">{{ __('Record') }}</button>
    </div>
</div>
