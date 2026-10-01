<?php

use Livewire\Component;
use Livewire\Attributes\Title;

new #[Title('Appearance settings')] class extends Component {
    //
}; ?>

<x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Dark, light or system')">
    <div x-data class="grid grid-cols-3 gap-2" data-test="appearance">
        @foreach (['dark' => ['icon' => 'bedtime', 'label' => __('Dark')], 'light' => ['icon' => 'light_mode', 'label' => __('Light')], 'system' => ['icon' => 'devices', 'label' => __('System')]] as $mode => $option)
            <button type="button" x-on:click="$flux.appearance = @js($mode)"
                    class="flex h-24 flex-col items-center justify-center gap-2 rounded-btn border text-sm font-medium transition active:scale-[0.97]"
                    :class="$flux.appearance === @js($mode) ? 'border-accent bg-accent/14 text-accent' : 'border-transparent bg-surface text-ink-2'">
                <x-ui.icon :name="$option['icon']" :size="26" />
                {{ $option['label'] }}
            </button>
        @endforeach
    </div>
</x-pages::settings.layout>
