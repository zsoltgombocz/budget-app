<?php

use App\Livewire\Actions\Logout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Settings')] class extends Component {
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

@php
    $user = auth()->user();
    $groups = [
        [
            ['route' => 'budget.edit', 'icon' => 'tune', 'title' => __('Budget'), 'subtitle' => __('Period and base currency')],
            ['route' => 'notifications.edit', 'icon' => 'notifications', 'title' => __('Notifications'), 'subtitle' => __('Daily reminder, due items, this device')],
            ['route' => 'appearance.edit', 'icon' => 'palette', 'title' => __('Appearance'), 'subtitle' => __('Theme, language and time zone')],
        ],
        [
            ['route' => 'profile.edit', 'icon' => 'person', 'title' => __('Profile'), 'subtitle' => __('Name, email, delete account')],
            ['route' => 'security.edit', 'icon' => 'lock', 'title' => __('Security'), 'subtitle' => __('Passkeys')],
        ],
        [
            ['route' => 'dashboard', 'params' => ['bemutato' => 1], 'test' => 'how-it-works', 'icon' => 'lightbulb', 'title' => __('How it works'), 'subtitle' => __('Plan, recording spending, closing the period')],
            ['route' => 'install', 'icon' => 'install_mobile', 'title' => __('Install the app'), 'subtitle' => __('Steps for your phone and browser')],
            ['route' => 'contact', 'icon' => 'mail', 'title' => __('Contact'), 'subtitle' => __('E-mail, bug reports and ideas')],
            ['route' => 'changelog', 'params' => ['honnan' => 'beallitasok'], 'icon' => 'celebration', 'title' => __('What’s new'), 'subtitle' => __('Version :version', ['version' => config('app.version')])],
        ],
    ];
@endphp

<div class="pb-6">
    {{-- A tab of its own: no back arrow, the tab bar is the way out. --}}
    <x-ui.page-header :title="__('Settings')" data-test="settings-title" />

    <div class="mx-4 mt-5 flex items-center gap-3 rounded-card bg-surface px-[18px] py-4">
        <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-accent/14 text-[15px] font-semibold text-accent">{{ $user->initials() }}</span>
        <div class="min-w-0">
            <div class="truncate text-[15px] font-semibold">{{ $user->name }}</div>
            <div class="truncate text-[13px] text-muted">{{ $user->email }}</div>
        </div>
    </div>

    @foreach ($groups as $group)
        <div class="mx-4 mt-3 overflow-hidden rounded-card bg-surface">
            @foreach ($group as $item)
                <a href="{{ route($item['route'], $item['params'] ?? []) }}" wire:navigate @class(['flex items-center gap-3 px-[18px] py-3.5 transition active:bg-ink/4', 'border-b border-line' => ! $loop->last]) data-test="settings-{{ $item['test'] ?? $item['route'] }}">
                    <x-ui.icon-tile :icon="$item['icon']" :size="36" />
                    <span class="min-w-0 flex-1">
                        <span class="block text-[15px] font-medium">{{ $item['title'] }}</span>
                        <span class="block truncate text-xs text-muted">{{ $item['subtitle'] }}</span>
                    </span>
                    <x-ui.icon name="chevron_right" :size="22" class="text-faint" />
                </a>
            @endforeach
        </div>
    @endforeach

    {{-- A row like the others, only in the danger colour and without a chevron: it does not open a page. --}}
    <div class="mx-4 mt-3 overflow-hidden rounded-card bg-surface">
        <button type="button" wire:click="logout" class="focus-ring flex w-full items-center gap-3 px-[18px] py-3.5 text-left transition active:bg-ink/4" data-test="logout-button">
            <x-ui.icon-tile icon="logout" tone="danger" :size="36" />
            <span class="min-w-0 flex-1 text-[15px] font-medium text-danger">{{ __('Log out') }}</span>
        </button>
    </div>
</div>
