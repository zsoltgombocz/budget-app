@props([
    'show',
    'close',
    'label' => null,
    'title' => null,
    'header' => true,
    'full' => true,
    'top' => null,
    'z' => 'z-50',
    'bodyClass' => '',
    'footerDivider' => true,
])

{{--
    The app's bottom sheet, driven by the surrounding Alpine scope: `show` is the expression
    that opens it, `close` the statement that closes it (Cancel, backdrop tap, Escape).
    Accessible name and header title: the static `label` or the Alpine `title` expression;
    header=false drops the Cancel/title row for sheets with their own content on top.
    full: tall sheet (top offset, `top` overrides it); otherwise it grows with its content.
    Slots: intro (one line under the title), action (header right), footer (sticky, e.g. the
    save button) and pad (an x-ui.amount-pad that slides over the footer).
    Focus, Escape, Tab and the iOS-safe scroll lock come from the appDialog helper (resources/js/dialog.js).
--}}
<div x-data="appDialog" x-effect="dialogSync({{ $show }})"
     x-on:keydown.escape.window="dialogEscape($event) && ({{ $close }})"
     x-on:keydown.tab="dialogTrap($event)"
     x-show="{{ $show }}" x-cloak
     @class(['fixed inset-0', $z])
     role="dialog" aria-modal="true"
     @if ($label) aria-label="{{ $label }}" @elseif ($title) :aria-label="{{ $title }}" @endif>
    <div x-show="{{ $show }}" x-transition.opacity class="absolute inset-0 bg-black/55" x-on:click="{{ $close }}" aria-hidden="true"></div>
    <div x-show="{{ $show }}" x-ref="dialogPanel" tabindex="-1"
         x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
         x-transition:leave="transition duration-200 ease-in" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
         {{ $attributes->class([
             'absolute inset-x-0 bottom-0 mx-auto flex max-w-lg flex-col overflow-hidden rounded-t-[30px] bg-surface text-ink outline-none',
             ($top ?? 'top-[calc(var(--safe-top)+0.75rem)]') => $full,
             'max-h-[calc(100dvh-var(--safe-top)-0.75rem)]' => ! $full,
         ]) }}>
        <div class="mx-auto mt-2 h-[5px] w-9 shrink-0 rounded-full bg-ink/18" aria-hidden="true"></div>
        @if ($header)
            <div class="mt-1 grid h-11 shrink-0 grid-cols-[72px_1fr_72px] items-center px-4">
                <button type="button" class="-ml-2 h-11 rounded-xl px-2 text-left text-[15px] text-muted focus-ring" x-on:click="{{ $close }}">{{ __('Cancel') }}</button>
                @if ($label)
                    <h2 class="truncate text-center text-base font-semibold">{{ $label }}</h2>
                @else
                    <h2 class="truncate text-center text-base font-semibold" x-text="{{ $title }}"></h2>
                @endif
                <div class="flex justify-end">{{ $action ?? '' }}</div>
            </div>
        @endif
        @isset($intro)
            <p class="shrink-0 px-6 pb-3 text-center text-[13px] leading-snug text-muted">{{ $intro }}</p>
        @endisset
        <div @class([
            'no-scrollbar min-h-0 flex-1 overflow-y-auto overscroll-contain px-4',
            'pb-4' => isset($footer),
            'pb-[calc(1.25rem+env(safe-area-inset-bottom))]' => ! isset($footer),
            $bodyClass,
        ])>
            {{ $slot }}
        </div>
        @isset($footer)
            <div @class(['shrink-0 px-4 pb-[calc(0.75rem+env(safe-area-inset-bottom))]', 'border-t border-line pt-3' => $footerDivider])>{{ $footer }}</div>
        @endisset
        {{ $pad ?? '' }}
    </div>
</div>
