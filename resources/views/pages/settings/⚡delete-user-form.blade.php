<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

new class extends Component {
    public string $confirmation = '';

    /**
     * Delete the account. There is no password, so the user types their email address.
     */
    public function deleteUser(Logout $logout): void
    {
        if (Str::lower(trim($this->confirmation)) !== Str::lower(Auth::user()->email)) {
            throw ValidationException::withMessages(['confirmation' => __('Type your email address exactly to confirm.')]);
        }

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<div x-data="{ open: false }" class="mt-6 rounded-card border border-danger/28 bg-danger/8 p-[18px]">
    <div class="text-[15px] font-semibold">{{ __('Delete account') }}</div>
    <div class="mt-1 text-[13px] leading-snug text-muted">{{ __('Deletes your account and every budget data for good.') }}</div>

    <x-ui.button x-show="! open" variant="danger" size="md" icon="delete" x-on:click="open = true" class="mt-3" data-test="delete-user-button">{{ __('Delete account') }}</x-ui.button>

    <form x-show="open" x-cloak wire:submit="deleteUser" class="mt-3 flex flex-col gap-3">
        <flux:input wire:model="confirmation" :label="__('Type your email address to confirm')" type="email" autocomplete="off" />
        <div class="flex gap-2">
            <x-ui.button variant="secondary" size="md" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button type="submit" variant="danger" size="md" class="flex-1 !bg-danger !text-white" data-test="confirm-delete-user-button">{{ __('Delete account') }}</x-ui.button>
        </div>
    </form>
</div>
