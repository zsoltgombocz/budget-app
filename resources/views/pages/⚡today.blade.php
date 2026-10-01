<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Today')] class extends Component {
    //
}; ?>

<div class="flex flex-col gap-4">
    <flux:heading size="xl">{{ __('Today') }}</flux:heading>
</div>
