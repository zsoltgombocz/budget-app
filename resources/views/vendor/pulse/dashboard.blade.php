<x-pulse>
    <div class="col-span-full -mb-2 flex justify-end text-sm">
        <a href="{{ url('/admin') }}" class="font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100">← {{ __('Back to the admin') }}</a>
    </div>

    <livewire:pulse.servers cols="full" />

    <livewire:pulse.usage cols="4" rows="2" />

    <livewire:pulse.queues cols="4" />

    <livewire:pulse.security-events cols="4" />

    <livewire:pulse.slow-requests cols="6" />

    <livewire:pulse.exceptions cols="6" />

    <livewire:pulse.slow-queries cols="6" />

    <livewire:pulse.slow-jobs cols="6" />

    <livewire:pulse.cache cols="6" />

    <livewire:pulse.slow-outgoing-requests cols="6" />
</x-pulse>
