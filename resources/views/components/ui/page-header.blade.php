@props(['title', 'subtitle' => null])

<div {{ $attributes->class(['flex items-end justify-between gap-3 px-6 pt-3.5']) }}>
    <div class="min-w-0">
        <h1 class="truncate text-[30px] font-semibold tracking-[-0.03em]">{{ $title }}</h1>
        @if ($subtitle)
            <div class="num mt-0.5 text-[13px] text-muted">{{ $subtitle }}</div>
        @endif
    </div>
    <div class="flex shrink-0 gap-2">
        {{ $actions ?? '' }}
    </div>
</div>
