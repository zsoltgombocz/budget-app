<?php

use App\Actions\Auth\SendMagicLink;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public bool $register = false;

    public string $name = '';

    public string $email = '';

    public ?string $sentTo = null;

    public function send(SendMagicLink $sendMagicLink): void
    {
        $this->validate([
            'name' => $this->register ? ['required', 'string', 'max:80'] : ['nullable'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $key = 'magic-link:'.Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        RateLimiter::hit($key, 600);

        $sendMagicLink->handle($this->email, $this->register ? $this->name : null);

        $this->sentTo = Str::lower(trim($this->email));
    }

    public function again(): void
    {
        $this->sentTo = null;
    }
}; ?>

<div>
    @if ($sentTo)
        <div class="flex flex-col gap-4" data-test="magic-link-sent">
            <div class="flex items-start gap-3 rounded-[18px] bg-accent/12 p-4">
                <x-ui.icon name="mark_email_read" :size="24" class="text-accent" />
                <div class="text-sm leading-snug text-ink-2">
                    <div class="font-semibold text-ink">{{ __('Check your inbox') }}</div>
                    {{ __('If :email has an account, a sign-in link is on its way. It works once, for :minutes minutes. Look in the spam folder too.', ['email' => $sentTo, 'minutes' => SendMagicLink::MINUTES]) }}
                </div>
            </div>
            <x-ui.button variant="secondary" wire:click="again">{{ __('Use another email') }}</x-ui.button>
        </div>
    @else
        <form wire:submit="send" class="flex flex-col gap-4">
            @if ($register)
                <flux:input wire:model="name" :label="__('Name')" type="text" required autocomplete="name" />
            @endif
            <flux:input wire:model="email" :label="__('Email address')" type="email" required autocomplete="email" inputmode="email" placeholder="email@example.com" data-test="magic-link-email" />
            <x-ui.button type="submit" wire:loading.attr="disabled" class="w-full" data-test="magic-link-send">
                {{ $register ? __('Create account') : __('Email me a sign-in link') }}
            </x-ui.button>
        </form>
    @endif
</div>
