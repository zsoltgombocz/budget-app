<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Register')] #[Layout('layouts::auth')] class extends Component {
    /**
     * Production is invite only; the page exists only while registration is open.
     */
    public function mount(): void
    {
        abort_unless(config()->boolean('budget.registration_open'), 404);
    }
}; ?>

<div class="flex flex-col gap-6" x-data="{ codeSent: false }" x-on:magic-link-step.window="codeSent = $event.detail.sent">
    <div x-show="! codeSent" data-test="register-intro">
        <x-auth-header :title="__('Create an account')" :description="__('Just your name and email. We send a code that signs you in, no password needed.')" />
    </div>

    <livewire:auth.magic-link-form :register="true" />

    <div class="text-center text-sm text-muted">
        {{ __('Already have an account?') }}
        <x-ui.link :href="route('login')" wire:navigate>{{ __('Log in') }}</x-ui.link>
    </div>
</div>
