@props(['on' => false])

{{--
    Design switch; pass wire:click or x-on:click to flip it, and an aria-label. The visual is
    24×40, the invisible ::before widens the touch target to 44×60 without moving the layout.
--}}
<button type="button" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}" {{ $attributes->class([
    'focus-ring relative flex h-6 w-10 shrink-0 rounded-full p-0.5 transition-colors before:absolute before:-inset-2.5',
    'justify-end bg-accent' => $on,
    'justify-start bg-zinc-600' => ! $on,
]) }}><span class="block size-5 rounded-full bg-white shadow-sm"></span></button>
