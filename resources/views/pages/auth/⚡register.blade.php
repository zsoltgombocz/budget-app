<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Register')] #[Layout('layouts::auth')] class extends Component {
    //
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header :title="__('Create an account')" :description="__('Just your name and email. We send a code that signs you in, no password needed.')" />

    <livewire:auth.magic-link-form :register="true" />

    <div class="text-center text-sm text-muted">
        {{ __('Already have an account?') }}
        <a href="{{ route('login') }}" wire:navigate class="font-medium text-accent">{{ __('Log in') }}</a>
    </div>
</div>
