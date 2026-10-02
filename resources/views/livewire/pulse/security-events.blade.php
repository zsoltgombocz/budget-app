<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        :name="__('Security events')"
        x-bind:title="`Time: {{ number_format($time) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        :details="__('past :period', ['period' => $this->periodForHumans()])"
    >
        <x-slot:icon>
            <x-pulse::icons.bug-ant />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.15s="">
        @if ($events->isEmpty())
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <colgroup>
                    <col width="100%" />
                    <col width="0%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>{{ __('Event') }}</x-pulse::th>
                        <x-pulse::th class="text-right">{{ __('Latest') }}</x-pulse::th>
                        <x-pulse::th class="text-right">{{ __('Count') }}</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($events->take(100) as $event)
                        <tr wire:key="{{ $event->event.$event->ip }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $event->event.$event->ip }}-row">
                            <x-pulse::td class="max-w-[1px]">
                                <span class="block truncate text-sm text-gray-900 dark:text-gray-100">{{ $event->event }}</span>
                                <code class="mt-1 block truncate text-xs text-gray-500 dark:text-gray-400">{{ $event->ip }}</code>
                            </x-pulse::td>
                            <x-pulse::td numeric class="font-bold text-gray-700 dark:text-gray-300">
                                {{ $event->latest->ago(syntax: Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true) }}
                            </x-pulse::td>
                            <x-pulse::td numeric class="font-bold text-gray-700 dark:text-gray-300">
                                {{ number_format($event->count) }}
                            </x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
