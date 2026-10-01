@props(['on' => false])

{{-- Design switch; pass wire:click or x-on:click to flip it. --}}
<button type="button" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}" {{ $attributes->class([
    'flex h-6 w-10 shrink-0 rounded-full p-0.5 transition-colors',
    'justify-end bg-accent' => $on,
    'justify-start bg-zinc-600' => ! $on,
]) }}><span class="block size-5 rounded-full bg-white shadow-sm"></span></button>
