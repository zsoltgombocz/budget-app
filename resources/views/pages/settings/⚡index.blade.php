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
            ['route' => 'budget.edit', 'icon' => 'tune', 'title' => __('Budget'), 'subtitle' => __('Period, currency, language, leftover rule')],
            ['route' => 'notifications.edit', 'icon' => 'notifications', 'title' => __('Notifications'), 'subtitle' => __('Daily reminder, due items, this device')],
            ['route' => 'appearance.edit', 'icon' => 'palette', 'title' => __('Appearance'), 'subtitle' => __('Dark, light or system')],
        ],
        [
            ['route' => 'profile.edit', 'icon' => 'person', 'title' => __('Profile'), 'subtitle' => __('Name, email, delete account')],
            ['route' => 'security.edit', 'icon' => 'lock', 'title' => __('Security'), 'subtitle' => __('Passkeys')],
        ],
        [
            ['route' => 'install', 'icon' => 'install_mobile', 'title' => __('Install the app'), 'subtitle' => __('Steps for your phone and browser')],
            ['route' => 'changelog', 'icon' => 'celebration', 'title' => __('What’s new'), 'subtitle' => __('Version :version', ['version' => config('app.version')])],
        ],
    ];
@endphp

<div class="pb-6">
    <div class="flex items-center gap-3 px-4 pt-3">
        <x-ui.icon-button icon="arrow_back" :href="route('dashboard')" wire:navigate :label="__('Back')" />
        <h1 class="text-[30px] font-semibold tracking-[-0.03em]">{{ __('Settings') }}</h1>
    </div>

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
                <a href="{{ route($item['route']) }}" wire:navigate @class(['flex items-center gap-3 px-[18px] py-3.5 transition active:bg-ink/4', 'border-b border-line' => ! $loop->last]) data-test="settings-{{ $item['route'] }}">
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

    <div class="mx-4 mt-3">
        <x-ui.button variant="secondary" icon="logout" wire:click="logout" class="w-full !text-danger" data-test="logout-button">{{ __('Log out') }}</x-ui.button>
    </div>
</div>
