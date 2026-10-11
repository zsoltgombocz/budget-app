@props(['label', 'aside' => null, 'group' => false])

{{--
    Label above a block of rows: normal-case and semibold. group: the uppercase, letter-spaced
    variant, kept for the Plan page's group headers only (as in the design).
--}}
<div {{ $attributes->class([
    'flex justify-between gap-3 px-1.5 pb-2 font-semibold text-muted',
    'text-[13px]' => ! $group,
    'text-xs uppercase tracking-[0.06em]' => $group,
]) }}>
    <span>{{ $label }}</span>
    @if ($aside !== null)<span class="num shrink-0">{{ $aside }}</span>@endif
</div>
