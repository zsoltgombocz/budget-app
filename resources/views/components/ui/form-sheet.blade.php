@props(['show', 'close', 'title' => null, 'label' => null, 'full' => true])

{{--
    Form sheet driven by the surrounding Alpine scope: `show` is the expression that opens it,
    `close` the statement that closes it; the header is the static `label` or the Alpine `title` expression. Slots: intro
    (one line that says what the sheet does), action (header right), footer (sticky save button)
    and pad (an x-ui.amount-pad that slides over the footer).
--}}
<div x-show="{{ $show }}" x-cloak class="fixed inset-0 z-50" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-black/55" x-on:click="{{ $close }}"></div>
    <div x-show="{{ $show }}"
         x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
         {{ $attributes->class([
             'absolute inset-x-0 bottom-0 mx-auto flex max-w-lg flex-col overflow-hidden rounded-t-[30px] bg-surface text-ink',
             'top-[calc(var(--safe-top)+0.75rem)]' => $full,
             'max-h-[calc(100dvh-var(--safe-top)-0.75rem)]' => ! $full,
         ]) }}>
        <div class="mx-auto mt-2 h-[5px] w-9 shrink-0 rounded-full bg-ink/18"></div>
        <div class="mt-1 grid h-11 shrink-0 grid-cols-[72px_1fr_72px] items-center px-4">
            <button type="button" class="text-left text-[15px] text-muted" x-on:click="{{ $close }}">{{ __('Cancel') }}</button>
            @if ($label)
                <div class="truncate text-center text-base font-semibold">{{ $label }}</div>
            @else
                <div class="truncate text-center text-base font-semibold" x-text="{{ $title }}"></div>
            @endif
            <div class="flex justify-end">{{ $action ?? '' }}</div>
        </div>
        @isset($intro)
            <p class="shrink-0 px-6 pb-3 text-center text-[13px] leading-snug text-muted">{{ $intro }}</p>
        @endisset
        <div class="no-scrollbar min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 pb-4">
            {{ $slot }}
        </div>
        @isset($footer)
            <div class="shrink-0 border-t border-line px-4 pb-[calc(0.75rem+env(safe-area-inset-bottom))] pt-3">{{ $footer }}</div>
        @endisset
        {{ $pad ?? '' }}
    </div>
</div>
