@props(['title' => null, 'hint' => null])

{{-- A titled group of form rows on a sheet, with an optional explanation below it. --}}
<section {{ $attributes->class(['mt-5 first:mt-1']) }}>
    @if ($title)<div class="px-1.5 pb-2 text-xs font-semibold uppercase tracking-[0.06em] text-muted">{{ $title }}</div>@endif
    <div class="divide-y divide-line overflow-hidden rounded-2xl bg-surface-2">{{ $slot }}</div>
    @if ($hint)<p class="px-1.5 pt-2 text-xs leading-snug text-muted">{{ $hint }}</p>@endif
</section>
