@props(['name', 'label', 'hint' => null, 'error' => null, 'fallback' => '–'])

{{-- Form row showing an amount; tapping it opens the x-ui.amount-pad for that field. With an error it is marked invalid and described by the message. --}}
@php $errorId = 'error-'.\Illuminate\Support\Str::random(8); @endphp
<button type="button" x-on:click="focus(@js($name), @js($label))"
        @if ($error) :aria-invalid="errors[@js($error)] ? 'true' : 'false'" :aria-describedby="errors[@js($error)] ? @js($errorId) : null" @endif
        {{ $attributes->class(['flex min-h-[52px] w-full items-center justify-between gap-3 px-4 py-2.5 text-left transition-colors']) }}
        :class="active === @js($name) && 'bg-accent/10'">
    <span class="min-w-0">
        <span class="block text-[15px]">{{ $label }}</span>
        @if ($hint)<span class="mt-0.5 block text-xs leading-snug text-muted">{{ $hint }}</span>@endif
        @if ($error)<span id="{{ $errorId }}" class="mt-0.5 block text-xs text-danger" x-show="errors[@js($error)]" x-text="errors[@js($error)]"></span>@endif
    </span>
    <span class="flex shrink-0 items-center gap-1">
        <span class="num text-[17px] font-semibold" :class="filled(@js($name)) ? 'text-accent' : 'text-muted'" x-text="display(@js($name), @js($fallback))"></span>
        <x-ui.icon name="edit" :size="16" class="text-faint" />
    </span>
</button>
