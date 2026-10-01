@props(['label', 'aside' => null])

<div {{ $attributes->class(['flex justify-between px-1.5 pb-2 text-xs font-semibold uppercase tracking-[0.06em] text-muted']) }}>
    <span>{{ $label }}</span>
    @if ($aside !== null)<span class="num">{{ $aside }}</span>@endif
</div>
