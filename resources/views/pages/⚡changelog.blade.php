<?php

use App\Support\Changelog;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('What’s new')] class extends Component {
    //
}; ?>

@php
    // Like every settings page: a back arrow on the left. From Settings it goes back there, from the new-version prompt to Today.
    $fromSettings = request()->query('honnan') === 'beallitasok';
@endphp
<div x-data="{ init() { try { localStorage.setItem('seen-version', @js(Changelog::version())) } catch (e) {} } }">
    <x-pages::settings.layout :back="$fromSettings ? 'settings' : 'dashboard'" :heading="__('What’s new')" :subheading="__('Version :version', ['version' => Changelog::version()])">
    <div class="flex flex-col gap-3" data-motion="stagger">
        @foreach (Changelog::all() as $entry)
            <x-ui.card class="p-[18px]" wire:key="version-{{ $entry['version'] }}" data-test="changelog-{{ $entry['version'] }}">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-1.5">
                        <span class="num inline-flex h-[22px] items-center rounded-full bg-ink/8 px-2 text-xs font-semibold text-ink-2" data-test="version-badge">{{ $entry['version'] }}</span>
                        @if ($loop->first)<span class="inline-flex h-[22px] items-center rounded-full bg-accent/16 px-2 text-xs font-semibold text-accent">{{ __('Current') }}</span>@endif
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
    </x-pages::settings.layout>
</div>
