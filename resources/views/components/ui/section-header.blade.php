@props(['title', 'subtitle' => null, 'as' => 'h2'])

{{--
    Header of a second-level section on a page (Pockets → "Loans"): title 20/600, an optional
    one-line subtitle 13 muted, and an action on the right aligned to the bottom.
    Spacing: px-5 like the section labels and 24px above (a new section); pass a class to change it.
    actions (slot): a secondary sm button with an icon, e.g.
        <x-slot:actions><x-ui.button variant="secondary" size="sm" icon="add">New loan</x-ui.button></x-slot:actions>
    as: the heading element (h2 by default; h3 under an h2 section).
    For a small label above a group of rows use x-ui.section-label; for the page title x-ui.page-header.
--}}
<div {{ $attributes->class(['flex items-end justify-between gap-3 px-5 pb-3 pt-6']) }}>
    <div class="min-w-0">
        <{{ $as }} class="truncate text-xl font-semibold tracking-[-0.02em]">{{ $title }}</{{ $as }}>
        @if ($subtitle)
            <div class="mt-0.5 truncate text-[13px] text-muted">{{ $subtitle }}</div>
        @endif
    </div>
    @isset($actions)
        <div class="flex shrink-0 gap-2">{{ $actions }}</div>
    @endisset
</div>
