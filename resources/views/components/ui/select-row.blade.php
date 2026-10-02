@props(['label', 'hint' => null])

{{-- Form row with a native select on the right; extra attributes go to the select. --}}
<label class="flex min-h-[52px] items-center justify-between gap-3 px-4 py-2.5">
    <span class="min-w-0">
        <span class="block text-[15px]">{{ $label }}</span>
        @if ($hint)<span class="mt-0.5 block text-xs leading-snug text-muted">{{ $hint }}</span>@endif
    </span>
    <span class="relative flex max-w-[55%] items-center">
        <select {{ $attributes->class(['w-full appearance-none truncate bg-transparent pr-6 text-right text-[15px] text-ink-2 outline-none']) }}>{{ $slot }}</select>
        <x-ui.icon name="expand_more" :size="18" class="pointer-events-none absolute right-0 text-muted" />
    </span>
</label>
