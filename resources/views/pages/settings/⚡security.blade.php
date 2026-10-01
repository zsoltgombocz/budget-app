<?php

use Laravel\Passkeys\Actions\DeletePasskey;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Security settings')] class extends Component {
    /** @var list<array{id: int, name: string, authenticator: string|null, created_at_diff: string, last_used_at_diff: string|null}> */
    #[Locked]
    public array $passkeys = [];

    public function mount(): void
    {
        $this->loadPasskeys();
    }

    public function loadPasskeys(): void
    {
        $this->passkeys = auth()->user()->passkeys()
            ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(fn ($passkey): array => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at_diff' => $passkey->created_at->diffForHumans(),
                'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
            ])
            ->values()
            ->all();
    }

    public function deletePasskey(int $passkeyId, DeletePasskey $deletePasskey): void
    {
        $passkey = auth()->user()->passkeys()->findOrFail($passkeyId);

        $deletePasskey(auth()->user(), $passkey);
        $this->loadPasskeys();

        $this->dispatch('app-toast', title: __('Passkey removed.'), icon: 'delete');
    }
}; ?>

<x-pages::settings.layout :heading="__('Security')" :subheading="__('Passkeys')">
    <x-ui.card class="p-[18px]">
        <div class="flex items-start gap-3">
            <x-ui.icon-tile icon="lock" tone="accent" />
            <p class="text-[13px] leading-relaxed text-muted">{{ __('There is no password. You sign in with a link we email you, or with a passkey: Face ID, Touch ID or your phone’s screen lock, faster and safer.') }}</p>
        </div>
    </x-ui.card>

    <div class="mt-3 overflow-hidden rounded-card bg-surface" data-test="passkeys">
        @forelse ($passkeys as $passkey)
            <div wire:key="passkey-{{ $passkey['id'] }}" x-data="{ confirming: false }" @class(['px-[18px] py-3.5', 'border-b border-line' => ! $loop->last])>
                <div class="flex items-center gap-3">
                    <x-ui.icon-tile icon="lock" :size="36" />
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-[15px] font-medium">{{ $passkey['name'] }}</div>
                        <div class="truncate text-xs text-muted">
                            {{ __('Added :time', ['time' => $passkey['created_at_diff']]) }}@if ($passkey['last_used_at_diff']) · {{ __('Last used :time', ['time' => $passkey['last_used_at_diff']]) }}@endif
                        </div>
                    </div>
                    <button type="button" x-show="! confirming" x-on:click="confirming = true" class="text-danger" aria-label="{{ __('Remove passkey') }}"><x-ui.icon name="delete" :size="22" /></button>
                </div>
                <div x-show="confirming" x-cloak class="mt-3 flex gap-2">
                    <x-ui.button variant="secondary" size="sm" x-on:click="confirming = false">{{ __('Cancel') }}</x-ui.button>
                    <x-ui.button variant="danger" size="sm" class="flex-1" wire:click="deletePasskey({{ $passkey['id'] }})">{{ __('Remove passkey') }}</x-ui.button>
                </div>
            </div>
        @empty
            <div class="px-6 py-8 text-center">
                <div class="text-[15px] font-medium">{{ __('No passkeys yet') }}</div>
                <div class="mt-1 text-[13px] text-muted">{{ __('Add one to sign in with Face ID or Touch ID.') }}</div>
            </div>
        @endforelse
    </div>

    <div class="mt-3">
        <x-passkey-registration />
    </div>
</x-pages::settings.layout>
