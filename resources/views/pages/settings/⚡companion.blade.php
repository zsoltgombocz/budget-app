<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Morzsa's presence (S18): full, quiet (seen, speaks only at milestones and closing) or off
 * (numbers only; the Morzsa tab becomes "Haladás").
 */
new #[Title('Morzsa')] class extends Component {
    public string $mode = 'full';

    public function mount(): void
    {
        $this->mode = $this->user()->settings()->companion_mode;
    }

    public function updatedMode(): void
    {
        $this->validate(['mode' => ['required', Rule::in(['full', 'quiet', 'off'])]]);
        $this->user()->settings()->update(['companion_mode' => $this->mode]);
        $this->dispatch('app-toast', title: __('Saved'));
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

<x-pages::settings.layout :heading="__('Morzsa')" :subheading="__('Presence and animations')">
    <x-ui.card class="p-[18px]">
        <div class="text-[15px] font-semibold">{{ __('Morzsa’s presence') }}</div>
        <div class="mt-3 grid grid-cols-3 gap-1.5" role="radiogroup" aria-label="{{ __('Morzsa’s presence') }}">
            @foreach (['full' => __('Full'), 'quiet' => __('Quiet'), 'off' => __('Off')] as $value => $label)
                <x-ui.choice role="radio" :aria-checked="$mode === $value ? 'true' : 'false'" :selected="$mode === $value" wire:click="$set('mode', '{{ $value }}')" class="h-11 rounded-xl text-sm" data-test="companion-{{ $value }}">{{ $label }}</x-ui.choice>
            @endforeach
        </div>
        <p class="mt-3 text-[13px] leading-snug text-muted" data-test="companion-hint">
            @switch($mode)
                @case('quiet') {{ __('Morzsa is there, but only speaks at milestones and at closing.') }} @break
                @case('off') {{ __('Numbers only. The Morzsa tab becomes “Progress”: the streak, the goals and the milestones stay.') }} @break
                @default {{ __('Morzsa is there and says one sentence when there is something to say.') }}
            @endswitch
        </p>
    </x-ui.card>
    <p class="px-1.5 pt-3 text-xs leading-snug text-muted">{{ __('Animations are set under Appearance. If your phone asks for reduced motion, I always follow it.') }}</p>
</x-pages::settings.layout>
