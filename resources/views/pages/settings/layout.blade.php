@props(['heading' => null, 'subheading' => null])

{{-- A settings section: back to the settings menu, a title, then the content. --}}
<div class="pb-6">
    <div class="flex items-center gap-3 px-4 pt-6">
        <x-ui.icon-button icon="arrow_back" :href="route('settings')" wire:navigate :label="__('Back')" data-test="settings-back" />
        <div class="min-w-0">
            <h1 class="truncate text-[26px] font-semibold tracking-[-0.03em]">{{ $heading }}</h1>
            @if (filled($subheading))
                <div class="truncate text-[13px] text-muted">{{ $subheading }}</div>
            @endif
        </div>
    </div>

    <div class="px-4 pt-5">
        {{ $slot }}
    </div>
</div>
