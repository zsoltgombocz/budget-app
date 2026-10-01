<?php

use App\Support\Changelog;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('What’s new')] class extends Component {
    //
}; ?>

<div x-data x-init="try { localStorage.setItem('seen-version', @js(Changelog::version())) } catch (e) {}">
    <x-ui.page-header :title="__('What’s new')" :subtitle="__('Version :version', ['version' => Changelog::version()])">
        <x-slot name="actions">
            <x-ui.icon-button icon="close" :href="route('dashboard')" wire:navigate :label="__('Close')" />
        </x-slot>
    </x-ui.page-header>

    <div class="flex flex-col gap-3 px-4 pt-5" data-motion="stagger">
        @foreach (Changelog::all() as $entry)
            <x-ui.card class="p-[18px]" wire:key="version-{{ $entry['version'] }}" data-test="changelog-{{ $entry['version'] }}">
                <div class="flex items-baseline justify-between">
                    <div class="flex items-center gap-2">
                        <span class="rounded-md bg-ink/8 px-2 py-0.5 font-mono text-xs text-accent">{{ $entry['version'] }}</span>
                        @if ($loop->first)<span class="text-xs font-medium text-accent">{{ __('Current') }}</span>@endif
                    </div>
                    <span class="num text-xs text-muted">{{ \Carbon\CarbonImmutable::parse($entry['date'])->locale(app()->getLocale())->isoFormat('YYYY. MMM D.') }}</span>
                </div>
                <ul class="mt-3 flex flex-col gap-2">
                    @foreach (Changelog::changes($entry) as $change)
                        <li class="flex gap-2.5 text-sm leading-snug text-ink-2"><x-ui.icon name="check" :size="16" :weight="600" class="mt-0.5 text-accent" />{{ $change }}</li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endforeach
    </div>
</div>
