@props(['label' => null, 'error' => null])

<label class="block">
    @if ($label)<span class="mb-1.5 block text-[13px] text-muted">{{ $label }}</span>@endif
    <span class="relative flex h-12 items-center rounded-[14px] bg-surface-2 focus-within:ring-2 focus-within:ring-accent">
        <select {{ $attributes->class(['h-full w-full appearance-none bg-transparent pl-4 pr-10 text-[15px] text-ink outline-none']) }}>{{ $slot }}</select>
        <x-ui.icon name="expand_more" :size="20" class="pointer-events-none absolute right-3 text-muted" />
    </span>
    @if ($error)<span class="mt-1.5 block text-xs text-danger">{{ $error }}</span>@endif
</label>
