@props(['heading' => null, 'subheading' => null, 'back' => 'settings'])

{{-- A settings section: back to the settings menu, a title, then the content. --}}
<div class="pb-6">
    <x-ui.back-header class="px-4" :href="route($back)" :title="$heading" :subtitle="$subheading" />

    <div class="px-4 pt-5">
        {{ $slot }}
    </div>
</div>
