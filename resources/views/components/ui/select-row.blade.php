@props(['label', 'hint' => null, 'bindHint' => null])

{{-- Form row with a native select on the right; extra attributes go to the select. bindHint: an Alpine expression for a hint under the whole row (e.g. the chosen pocket's balance), hidden while empty. --}}
<label class="flex min-h-[52px] flex-wrap items-center justify-between gap-x-3 px-4 py-2.5 focus-within:bg-accent/10">
    <span class="min-w-0">
        <span class="block text-[15px]">{{ $label }}</span>
        @if ($hint)<span class="mt-0.5 block text-xs leading-snug text-muted">{{ $hint }}</span>@endif
    </span>
    <span class="relative flex max-w-[68%] items-center">
        <select {{ $attributes->class(['w-full appearance-none truncate bg-transparent pr-6 text-right text-[15px] text-ink-2 outline-none']) }}>{{ $slot }}</select>
        <x-ui.icon name="expand_more" :size="18" class="pointer-events-none absolute right-0 text-muted" />
    </span>
    {{-- Under the whole row, so the select keeps its width for the chosen name. --}}
    @if ($bindHint)<span class="num -mt-0.5 basis-full text-xs leading-snug text-muted" x-show="{{ $bindHint }}" x-text="{{ $bindHint }}"></span>@endif
</label>
