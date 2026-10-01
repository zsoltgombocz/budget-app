<?php

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Profile settings')] class extends Component {
    public string $name = '';
    public string $email = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
        ]);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $this->dispatch('app-toast', title: __('Profile updated.'));
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return ! Auth::user() instanceof MustVerifyEmail
            || (Auth::user() instanceof MustVerifyEmail && Auth::user()->hasVerifiedEmail());
    }
}; ?>

<x-pages::settings.layout :heading="__('Profile')" :subheading="__('Name, email, delete account')">
    <form wire:submit="updateProfileInformation" class="flex flex-col gap-4 rounded-card bg-surface p-[18px]">
        <flux:input wire:model="name" :label="__('Name')" type="text" required autocomplete="name" />

        <div>
            <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />
            <p class="mt-1.5 text-xs text-muted">{{ __('Sign-in links are sent to this address.') }}</p>

            @if ($this->hasUnverifiedEmail)
                <div class="mt-3 text-[13px] text-ink-2">
                    {{ __('Your email address is unverified.') }}
                    <button type="button" class="font-medium text-accent" wire:click.prevent="resendVerificationNotification">{{ __('Click here to re-send the verification email.') }}</button>
                    @if (session('status') === 'verification-link-sent')
                        <div class="mt-2 font-medium text-accent">{{ __('A new verification link has been sent to your email address.') }}</div>
                    @endif
                </div>
            @endif
        </div>

        <x-ui.button type="submit" class="w-full" data-test="update-profile-button">{{ __('Save') }}</x-ui.button>
    </form>

    @if ($this->showDeleteUser)
        <livewire:pages::settings.delete-user-form />
    @endif
</x-pages::settings.layout>
