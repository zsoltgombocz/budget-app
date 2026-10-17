@props([
    'show',
    'close',
    'label' => null,
    'title' => null,
    'header' => true,
    'full' => null,
    'top' => null,
    'z' => 'z-50',
    'bodyClass' => '',
    'footerDivider' => true,
    'closeLabel' => null,
])

{{--
    The app's bottom sheet, driven by the surrounding Alpine scope: `show` is the expression
    that opens it, `close` the statement that closes it (Cancel, backdrop tap, Escape).
    Accessible name and header title: the static `label` or the Alpine `title` expression. The
    title is ONE line (16/600, truncated): put secondary information ("· Personal loan") in the
    intro slot instead. header=false drops the Cancel/title row for sheets with their own content
    on top; closeLabel renames Cancel (e.g. "Got it" on a sheet that only explains).
    full: true = tall sheet (top offset, `top` overrides it); false = grows with its content.
    Left out, a sheet with a pad is tall and any other grows with its content (edit forms,
    menus, explainers). A short sheet with a pad grows to full height while the pad is open,
    so the numpad always fits.
    Slots:
      intro   one line under the title, centred, 13 muted.
      action  header right.
      danger  the destructive action at the end of the scrolling content, 24px under the rest:
              <x-slot:danger><x-ui.button variant="danger" size="md" icon="delete" class="w-full">…</x-ui.button></x-slot:danger>
      footer  sticky at the bottom: one primary lg button; a secondary action goes left of it
              as a text button.
      pad     an x-ui.amount-pad that slides over the footer.
    Focus, Escape, Tab and the iOS-safe scroll lock come from the appDialog helper (resources/js/dialog.js).
--}}
@php $full ??= isset($pad); @endphp
<div x-data="appDialog" x-effect="dialogSync({{ $show }})"
     x-on:keydown.escape.window="dialogEscape($event) && ({{ $close }})"
     x-on:keydown.tab="dialogTrap($event)"
     x-show="{{ $show }}" x-cloak
     @class(['fixed inset-0', $z])
     role="dialog" aria-modal="true"
     @if ($label) aria-label="{{ $label }}" @elseif ($title) :aria-label="{{ $title }}" @endif>
    <div x-show="{{ $show }}" x-transition.opacity.duration.200ms class="absolute inset-0 bg-black/55" x-on:click="{{ $close }}" aria-hidden="true"></div>
    <div x-show="{{ $show }}" x-ref="dialogPanel" tabindex="-1"
         x-transition:enter="transition duration-250 ease-[cubic-bezier(0.2,0.8,0.2,1)]" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
         x-transition:leave="transition duration-180 ease-in" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
         @if (! $full && isset($pad)) :class="active && @js($top ?? 'top-[calc(var(--safe-top)+0.75rem)]')" @endif
         {{ $attributes->class([
             'absolute inset-x-0 bottom-0 mx-auto flex max-w-lg flex-col overflow-hidden rounded-t-[30px] bg-surface text-ink outline-none',
             ($top ?? 'top-[calc(var(--safe-top)+0.75rem)]') => $full,
             'max-h-[calc(100dvh-var(--safe-top)-0.75rem)]' => ! $full,
         ]) }}>
        <div class="mx-auto mt-2 h-[5px] w-9 shrink-0 rounded-full bg-ink/18" aria-hidden="true"></div>
        @if ($header)
            {{-- Cancel sits 24px in (20px on a 320px phone), in line with the intro text, mirrored by the right column so the title stays centred. --}}
            <div class="mt-1 grid min-h-11 shrink-0 grid-cols-[64px_minmax(0,1fr)_64px] items-center gap-x-1 px-6 max-[360px]:px-5" data-test="sheet-header">
                <button type="button" class="-ml-2 h-11 rounded-xl px-2 text-left text-[15px] text-muted focus-ring" x-on:click="{{ $close }}">{{ $closeLabel ?? __('Cancel') }}</button>
                @if ($label)
                    <h2 class="truncate text-center text-base font-semibold leading-tight max-[360px]:text-[15px]" data-test="sheet-title">{{ $label }}</h2>
                @else
                    {{-- Only in the DOM while open, so a closed sheet leaves no empty heading behind. --}}
                    <div class="min-w-0"><template x-if="{{ $show }}"><h2 class="truncate text-center text-base font-semibold leading-tight max-[360px]:text-[15px]" x-text="{{ $title }}" :title="{{ $title }}" data-test="sheet-title"></h2></template></div>
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
            @isset($danger)
                <div class="mt-6" data-test="sheet-danger">{{ $danger }}</div>
            @endisset
        </div>
        @isset($footer)
            <div @class(['shrink-0 px-4 pb-[calc(0.75rem+env(safe-area-inset-bottom))]', 'border-t border-line pt-3' => $footerDivider])>{{ $footer }}</div>
        @endisset
        {{ $pad ?? '' }}
    </div>
</div>
