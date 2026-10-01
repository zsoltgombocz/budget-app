@props(['title' => null, 'tabs' => true])

<x-layouts::app.mobile :title="$title" :tabs="$tabs">
    {{ $slot }}
</x-layouts::app.mobile>
