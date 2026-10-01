@props(['name', 'label', 'hint' => null, 'error' => null, 'fallback' => '–'])

{{-- Form row showing an amount; tapping it opens the x-ui.amount-pad for that field. --}}
<button type="button" x-on:click="focus(@js($name), @js($label))"
        {{ $attributes->class(['flex min-h-[52px] w-full items-center justify-between gap-3 px-4 py-2.5 text-left transition-colors']) }}
        :class="active === @js($name) && 'bg-accent/10'">
    <span class="min-w-0">
        <span class="block text-[15px]">{{ $label }}</span>
        @if ($hint)<span class="mt-0.5 block text-xs leading-snug text-muted">{{ $hint }}</span>@endif
        @if ($error)<span class="mt-0.5 block text-xs text-danger" x-show="errors[@js($error)]" x-text="errors[@js($error)]"></span>@endif
    </span>
    <span class="flex shrink-0 items-center gap-1">
        <span class="num text-[17px] font-semibold" :class="filled(@js($name)) ? 'text-accent' : 'text-faint'" x-text="display(@js($name), @js($fallback))"></span>
        <x-ui.icon name="edit" :size="16" class="text-faint" />
    </span>
</button>
