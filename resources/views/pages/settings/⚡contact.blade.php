<?php

use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Contact')] class extends Component {
    /** The page the user came from, handed to the bug report. */
    #[Locked]
    public ?string $fromUrl = null;

    public function mount(): void
    {
        $previous = url()->previous();
        $this->fromUrl = str_starts_with($previous, url('/').'/') && ! str_contains($previous, '/settings') ? $previous : null;
    }
}; ?>

@php
    $email = config('budget.contact_email');
    $links = [
        ['route' => route('bug-report', array_filter(['honnan' => $fromUrl])), 'icon' => 'bug_report', 'title' => __('Report a bug'), 'subtitle' => __('Something not working? Tell us.'), 'test' => 'contact-bug'],
        ['route' => route('idea'), 'icon' => 'lightbulb', 'title' => __('Share an idea'), 'subtitle' => __('What would make the app better for you?'), 'test' => 'contact-idea'],
    ];
@endphp

<x-pages::settings.layout :heading="__('Contact')" :subheading="__('E-mail, bug reports and ideas')">
    <div class="flex flex-col gap-3">
        <x-ui.card class="p-[18px]" data-test="contact-info">
            <div class="text-[15px] font-semibold">MoneySight</div>
            <div class="mt-1 text-sm leading-normal text-pretty text-muted">{{ __('A personal budget tracker: you plan the month, record what you spend, and see what is left. It does not connect to your bank and never moves money.') }}</div>
            <div class="num mt-3 text-xs text-muted">{{ __('Version :version', ['version' => config('app.version')]) }}</div>
        </x-ui.card>

        @if (filled($email))
            <a href="mailto:{{ $email }}" class="flex items-center gap-3 rounded-card bg-surface px-[18px] py-3.5 transition active:bg-ink/4" data-test="contact-email">
                <x-ui.icon-tile icon="mail" :size="36" />
                <span class="min-w-0 flex-1">
                    <span class="block text-[15px] font-medium">{{ __('Write to us') }}</span>
                    <span class="block truncate text-xs text-muted">{{ $email }}</span>
                </span>
                <x-ui.icon name="chevron_right" :size="22" class="text-faint" />
            </a>
        @endif

        <div class="rounded-card bg-surface">
            @foreach ($links as $link)
                <a href="{{ $link['route'] }}" wire:navigate @class(['flex items-center gap-3 px-[18px] py-3.5 transition active:bg-ink/4', 'border-b border-line' => ! $loop->last]) data-test="{{ $link['test'] }}">
                    <x-ui.icon-tile :icon="$link['icon']" :size="36" />
                    <span class="min-w-0 flex-1">
                        <span class="block text-[15px] font-medium">{{ $link['title'] }}</span>
                        <span class="block truncate text-xs text-muted">{{ $link['subtitle'] }}</span>
                    </span>
                    <x-ui.icon name="chevron_right" :size="22" class="text-faint" />
                </a>
            @endforeach
        </div>
    </div>
</x-pages::settings.layout>
