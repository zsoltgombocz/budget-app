@props(['href', 'title', 'subtitle' => null, 'label' => null])

{{-- The one back pattern of the app: a round arrow, then the page title. --}}
<div {{ $attributes->class(['flex items-center gap-3 pt-8']) }}>
    <x-ui.icon-button icon="arrow_back" :href="$href" wire:navigate :label="$label ?? __('Back')" data-test="back" />
    <div class="min-w-0">
        <h1 class="truncate text-[26px] font-semibold tracking-[-0.03em]">{{ $title }}</h1>
        @if (filled($subtitle))
            <div class="num truncate text-[13px] text-muted">{{ $subtitle }}</div>
        @endif
    </div>
</div>
