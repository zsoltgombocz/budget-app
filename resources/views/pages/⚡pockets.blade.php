<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Pockets and loans')] class extends Component {
    //
}; ?>

<div class="flex flex-col gap-4">
    <flux:heading size="xl">{{ __('Pockets and loans') }}</flux:heading>
</div>
