@props(['model', 'label', 'hint' => null])

{{-- Form row with a switch bound to the Alpine expression `model`. --}}
<button type="button" role="switch" x-on:click="{{ $model }} = ! {{ $model }}" :aria-checked="{{ $model }} ? 'true' : 'false'"
        {{ $attributes->class(['flex min-h-[52px] w-full items-center justify-between gap-3 px-4 py-2.5 text-left']) }}>
    <span class="min-w-0">
        <span class="block text-[15px]">{{ $label }}</span>
        @if ($hint)<span class="mt-0.5 block text-xs leading-snug text-muted">{{ $hint }}</span>@endif
    </span>
    <span class="flex h-6 w-10 shrink-0 rounded-full p-0.5 transition-colors" :class="{{ $model }} ? 'justify-end bg-accent' : 'justify-start bg-zinc-600'"><span class="block size-5 rounded-full bg-white shadow-sm"></span></span>
</button>
