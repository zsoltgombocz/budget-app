<?php

use App\Actions\Auth\SendMagicLink;
use App\Models\User;
use App\Support\Hungarian;
use App\Support\SecurityEvents;
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
        abort_if($this->register && ! config()->boolean('budget.registration_open'), 404);

        $this->validate([
            'name' => $this->register ? ['required', 'string', 'max:80'] : ['nullable'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ], [
            'name.required' => __('Enter your name.'),
            'email.required' => __('Enter your email address.'),
            'email.email' => __('This does not look like an email address. It looks like this: name@example.com'),
        ]);

        $key = 'magic-link:'.Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            SecurityEvents::record('sign_in_throttled');

            throw ValidationException::withMessages([
                'email' => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        RateLimiter::hit($key, 600);

        // A disabled account cannot even start signing in: say so right away, send nothing.
        if (User::query()->where('email', Str::lower(trim($this->email)))->first()?->isDisabled()) {
            SecurityEvents::record('disabled_user');

            throw ValidationException::withMessages(['email' => __('Your account is disabled.')]);
        }

        $sendMagicLink->handle($this->email, $this->register ? $this->name : null);

        $this->sentTo = Str::lower(trim($this->email));
        // The page hides its intro and the passkey button while the code is being typed.
        $this->dispatch('magic-link-step', sent: true);
    }

    public function verify(SendMagicLink $sendMagicLink): void
    {
        if ($this->sentTo === null) {
            return;
        }

        $this->validate(['code' => ['required', 'string']]);

        $user = $sendMagicLink->consumeCode($this->sentTo, $this->code);

        if ($user === null) {
            SecurityEvents::record('wrong_code');
            $this->reset('code');

            throw ValidationException::withMessages(['code' => __('The code is wrong or has expired.')]);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        Auth::guard('web')->login($user, remember: true);
        session()->regenerate();

        $this->redirectIntended(route('dashboard'));
    }

    public function again(): void
    {
        $this->reset('sentTo', 'code');
        $this->dispatch('magic-link-step', sent: false);
    }
}; ?>

<div>
    @if ($sentTo)
        <div class="flex flex-col gap-4" data-test="magic-link-sent">
            <form wire:submit="verify" class="flex flex-col gap-3">
                <label class="block">
                    <span class="mb-3 flex items-center gap-2.5 text-[22px] font-semibold leading-tight tracking-[-0.02em] max-[359.98px]:text-[19px] max-[359.98px]:tracking-[-0.03em]"><x-ui.icon name="mark_email_read" :size="26" class="shrink-0 text-accent max-[359.98px]:hidden" />{{ __('Check your inbox') }}</span>
                    <span class="mb-1.5 block text-[13px] text-muted">{{ __('Code from the email') }}</span>
                    <input type="text" wire:model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" pattern="[0-9 ]*" autofocus
                           x-on:input="if ($event.target.value.replace(/\D/g, '').length === 6) $wire.verify()"
                           class="num h-14 w-full rounded-[14px] bg-surface-2 px-4 text-center text-[28px] font-semibold tracking-[0.35em] outline-none placeholder:text-muted focus:ring-2 focus:ring-accent"
                           placeholder="000000" data-test="login-code">
                    @error('code')<span class="mt-1.5 block text-xs text-danger">{{ $message }}</span>@enderror
                </label>
                <x-ui.button type="submit" class="w-full" data-test="login-code-submit">{{ __('Sign in') }}</x-ui.button>
            </form>

            {{-- Two lines up front; the rest only for whoever is still waiting for the email. --}}
            <div x-data="{ help: false, resent: false }" x-on:magic-link-step.window="resent = help && $event.detail.sent" class="flex flex-col">
                <p class="text-[13px] leading-snug text-pretty text-muted" data-test="magic-link-note">{{ __('If :email has an account, we sent a 6-digit code to it.', ['email' => app()->getLocale() === 'hu' ? Hungarian::withArticle($sentTo) : $sentTo]) }}</p>
                <div x-show="help" x-cloak class="mt-2 text-[13px] leading-snug text-pretty text-muted" data-test="magic-link-help">
                    {{ __('Look in the spam folder too. The code works for :minutes minutes; if it has expired, ask for a new one.', ['minutes' => SendMagicLink::MINUTES]) }}
                    <span x-show="resent" x-cloak class="mt-1 block text-ink-2" role="status" data-test="magic-link-resent">{{ __('We sent a new code.') }}</span>
                    @error('email')<span class="mt-1 block text-danger" role="alert">{{ $message }}</span>@enderror
                </div>
                <div class="-mx-2 mt-2 flex flex-wrap items-center justify-between gap-x-2">
                    <x-ui.button variant="text" size="sm" x-show="! help" x-on:click="help = true" data-test="magic-link-not-received">{{ __('Didn’t get it?') }}</x-ui.button>
                    <x-ui.button variant="text" size="sm" x-show="help" x-cloak x-on:click="resent = false; $wire.send()" wire:loading.attr="disabled" wire:target="send" data-test="magic-link-resend">{{ __('Send a new code') }}</x-ui.button>
                    <x-ui.button variant="text" size="sm" wire:click="again" data-test="magic-link-again">{{ __('Use another email') }}</x-ui.button>
                </div>
            </div>
        </div>
    @else
        {{-- novalidate: our Hungarian message instead of the browser's English bubble. --}}
        <form wire:submit="send" class="flex flex-col gap-4" novalidate>
            @if ($register)
                <x-ui.input wire:model="name" :label="__('Name')" type="text" required autocomplete="name" :error="$errors->first('name')" />
            @endif
            <x-ui.input wire:model="email" :label="__('Email address')" type="email" required autocomplete="email" inputmode="email" :placeholder="__('name@example.com')" :error="$errors->first('email')" data-test="magic-link-email" />
            <x-ui.button type="submit" wire:loading.attr="disabled" class="w-full" data-test="magic-link-send">
                {{ $register ? __('Create account') : __('Email me a sign-in code') }}
            </x-ui.button>
        </form>
    @endif
</div>
