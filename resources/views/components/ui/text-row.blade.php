@props(['label', 'error' => null])

{{-- Form row with an inline text input; extra attributes go to the input. --}}
<label class="block px-4 py-1.5">
    <span class="flex min-h-10 items-center gap-3">
        <span class="shrink-0 text-[15px]">{{ $label }}</span>
        <input type="text" {{ $attributes->class(['min-w-0 flex-1 bg-transparent text-right text-[15px] text-ink-2 outline-none placeholder:text-faint']) }}>
    </span>
    @if ($error)<span class="block pb-1 text-right text-xs text-danger" x-show="errors[@js($error)]" x-text="errors[@js($error)]"></span>@endif
</label>
