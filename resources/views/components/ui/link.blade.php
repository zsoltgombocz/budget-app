@props(['href' => null, 'icon' => null, 'type' => 'button'])

{{--
    A text action or link: the accent colour plus an underline, so it reads as something to tap.
    (The accent alone marks selection, e.g. a chosen pill, and is not underlined.)
    icon: a leading icon, e.g. "add" for the "new item" rows. Size and spacing come from the class.
--}}
@php $classes = 'focus-ring inline-flex items-center gap-2 rounded-lg font-medium text-accent-strong'; @endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>@if ($icon)<x-ui.icon :name="$icon" :size="20" />@endif<span class="underline decoration-accent-strong/45 decoration-1 underline-offset-[3px]">{{ $slot }}</span></a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>@if ($icon)<x-ui.icon :name="$icon" :size="20" />@endif<span class="underline decoration-accent-strong/45 decoration-1 underline-offset-[3px]">{{ $slot }}</span></button>
@endif
