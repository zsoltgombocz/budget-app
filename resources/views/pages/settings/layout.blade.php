@props(['heading' => null, 'subheading' => null, 'back' => 'settings'])

{{-- A settings section: back to the settings menu, a title, then the content. --}}
<div class="pb-6">
    {{-- The back button sits on the title's line; a subtitle wraps below the title, not below the button. --}}
    <div class="grid grid-cols-[36px_minmax(0,1fr)] items-center gap-x-3 px-4 pt-6">
        <x-ui.icon-button icon="arrow_back" :href="route($back)" wire:navigate :label="__('Back')" data-test="settings-back" />
        <h1 class="truncate text-[26px] font-semibold leading-tight tracking-[-0.03em]" data-test="settings-heading">{{ $heading }}</h1>
        @if (filled($subheading))
            <div class="col-start-2 mt-0.5 text-[13px] leading-snug text-pretty text-muted" data-test="settings-subheading">{{ $subheading }}</div>
        @endif
    </div>

    <div class="px-4 pt-5">
        {{ $slot }}
    </div>
</div>
