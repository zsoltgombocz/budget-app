<?php

use App\Actions\Auth\SendMagicLink;
use Illuminate\Support\Facades\Auth;
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

    public string $code = '';

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

    public function verify(SendMagicLink $sendMagicLink): void
    {
        if ($this->sentTo === null) {
            return;
        }

        $this->validate(['code' => ['required', 'string']]);

        $user = $sendMagicLink->consumeCode($this->sentTo, $this->code);

        if ($user === null) {
            $this->reset('code');

            throw ValidationException::withMessages(['code' => __('The code is wrong or has expired.')]);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        Auth::login($user, remember: true);
        session()->regenerate();

        $this->redirectIntended(route('dashboard'));
    }

    public function again(): void
    {
        $this->reset('sentTo', 'code');
    }
}; ?>

<div>
    @if ($sentTo)
        <div class="flex flex-col gap-4" data-test="magic-link-sent">
            <div class="flex items-start gap-3 rounded-[18px] bg-accent/12 p-4">
                <x-ui.icon name="mark_email_read" :size="24" class="text-accent" />
                <div class="text-sm leading-snug text-ink-2">
                    <div class="font-semibold text-ink">{{ __('Check your inbox') }}</div>
                    {{ __('If :email has an account, we sent a 6-digit code. It works for :minutes minutes. Look in the spam folder too.', ['email' => $sentTo, 'minutes' => SendMagicLink::MINUTES]) }}
                </div>
            </div>

            <form wire:submit="verify" class="flex flex-col gap-3">
                <label class="block">
                    <span class="mb-1.5 block text-[13px] text-muted">{{ __('Code from the email') }}</span>
                    <input type="text" wire:model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" pattern="[0-9 ]*" autofocus
                           x-on:input="if ($event.target.value.replace(/\D/g, '').length === 6) $wire.verify()"
                           class="num h-14 w-full rounded-[14px] bg-surface-2 px-4 text-center text-[28px] font-semibold tracking-[0.35em] outline-none focus:ring-2 focus:ring-accent"
                           placeholder="000000" data-test="login-code">
                    @error('code')<span class="mt-1.5 block text-xs text-danger">{{ $message }}</span>@enderror
                </label>
                <x-ui.button type="submit" class="w-full" data-test="login-code-submit">{{ __('Sign in') }}</x-ui.button>
            </form>

            <x-ui.button variant="ghost" size="md" wire:click="again">{{ __('Use another email') }}</x-ui.button>
        </div>
    @else
        <form wire:submit="send" class="flex flex-col gap-4">
            @if ($register)
                <flux:input wire:model="name" :label="__('Name')" type="text" required autocomplete="name" />
            @endif
            <flux:input wire:model="email" :label="__('Email address')" type="email" required autocomplete="email" inputmode="email" placeholder="email@example.com" data-test="magic-link-email" />
            <x-ui.button type="submit" wire:loading.attr="disabled" class="w-full" data-test="magic-link-send">
                {{ $register ? __('Create account') : __('Email me a sign-in code') }}
            </x-ui.button>
        </form>
    @endif
</div>
