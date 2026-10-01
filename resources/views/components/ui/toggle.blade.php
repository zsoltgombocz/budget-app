@props(['on' => false])

{{-- Design switch; pass wire:click or x-on:click to flip it. --}}
<button type="button" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}" {{ $attributes->class([
    'flex h-8 w-[52px] shrink-0 rounded-2xl p-[3px] transition-colors',
    'justify-end bg-accent' => $on,
    'justify-start bg-zinc-600' => ! $on,
]) }}><span class="block size-[26px] rounded-full bg-white shadow-sm"></span></button>
