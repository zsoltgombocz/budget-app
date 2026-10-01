@props(['icon', 'title', 'dashed' => true])

<div {{ $attributes->class(['flex flex-col items-center gap-2.5 rounded-card px-6 pb-[22px] pt-7 text-center', 'border border-dashed border-ink/14' => $dashed]) }}>
    <x-ui.icon-tile :icon="$icon" :size="56" :tone="$dashed ? 'accent' : 'muted'" />
    <div class="mt-1 text-lg font-semibold tracking-[-0.01em]">{{ $title }}</div>
    <div class="text-sm leading-normal text-pretty text-muted">{{ $slot }}</div>
    @isset($actions)
        <div class="mt-2 flex gap-2">{{ $actions }}</div>
    @endisset
</div>
