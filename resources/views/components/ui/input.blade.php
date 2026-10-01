@props(['label' => null, 'suffix' => null, 'error' => null, 'hint' => null])

<label class="block">
    @if ($label)<span class="mb-1.5 block text-[13px] text-muted">{{ $label }}</span>@endif
    <span class="flex h-12 items-center gap-2 rounded-[14px] bg-surface-2 px-4 focus-within:ring-2 focus-within:ring-accent">
        <input {{ $attributes->class(['num min-w-0 flex-1 bg-transparent text-[15px] text-ink outline-none placeholder:text-faint']) }} />
        @if ($suffix)<span class="text-sm text-muted">{{ $suffix }}</span>@endif
    </span>
    @if ($hint)<span class="mt-1.5 block text-xs text-muted">{{ $hint }}</span>@endif
    @if ($error)<span class="mt-1.5 block text-xs text-danger">{{ $error }}</span>@endif
</label>
