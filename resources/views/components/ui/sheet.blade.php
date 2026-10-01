@props(['name', 'title' => null, 'full' => false])

{{--
    Bottom sheet. Open with $dispatch('open-sheet', '{{ $name }}') or from Livewire with
    $this->dispatch('open-sheet', name: ...); close with 'close-sheet'.
--}}
<div x-data="{ open: false }"
     x-on:open-sheet.window="if (($event.detail?.name ?? $event.detail) === @js($name)) open = true"
     x-on:close-sheet.window="if (! $event.detail || ($event.detail?.name ?? $event.detail) === @js($name)) open = false"
     x-on:keydown.escape.window="open = false"
     x-effect="document.documentElement.classList.toggle('overflow-hidden', open)"
     x-cloak>
    <div x-show="open" class="fixed inset-0 z-50" role="dialog" aria-modal="true" @if ($title) aria-label="{{ $title }}" @endif>
            <div x-show="open" x-transition.opacity class="absolute inset-0 bg-black/55" x-on:click="open = false"></div>
            <div x-show="open"
                 x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
                 x-transition:leave="transition duration-200 ease-in" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
                 {{ $attributes->class([
                     'absolute inset-x-0 bottom-0 mx-auto flex max-w-lg flex-col rounded-t-[30px] bg-surface px-4 pb-[calc(1.5rem+env(safe-area-inset-bottom))] pt-2 text-ink',
                     'top-[max(2rem,env(safe-area-inset-top))]' => $full,
                     'max-h-[92dvh]' => ! $full,
                 ]) }}>
                <div class="mx-auto h-[5px] w-9 shrink-0 rounded-full bg-ink/18"></div>
                @if ($title)
                    <div class="mt-1 grid h-11 shrink-0 grid-cols-[72px_1fr_72px] items-center">
                        <button type="button" class="text-left text-[15px] text-muted" x-on:click="open = false">{{ __('Cancel') }}</button>
                        <div class="text-center text-base font-semibold">{{ $title }}</div>
                        <div class="text-right">{{ $headerAction ?? '' }}</div>
                    </div>
                @endif
                <div class="no-scrollbar -mx-4 min-h-0 flex-1 overflow-y-auto px-4">
                    {{ $slot }}
                </div>
            </div>
    </div>
</div>
