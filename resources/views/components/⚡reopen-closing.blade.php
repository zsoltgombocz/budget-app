<?php

use App\Actions\Budget\ReopenPeriod;
use App\Models\Period;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Undo the closing" on a closed summary (closing page, Month page). Shown only for the latest
 * closing until the month's regular end; other refusals appear when the user taps it.
 */
new class extends Component {
    #[Locked]
    public int $periodId;

    /**
     * The confirmation for window.appConfirm, or null with the reason in the "reopen" error.
     *
     * @return array{title: string, body: string, confirm: string}|null
     */
    public function dialog(ReopenPeriod $reopenPeriod): ?array
    {
        $this->resetErrorBag('reopen');

        try {
            return $reopenPeriod->preview($this->user(), $this->period)->confirmation();
        } catch (ValidationException $exception) {
            $this->addError('reopen', $exception->validator->errors()->first('reopen'));

            return null;
        }
    }

    public function reopen(ReopenPeriod $reopenPeriod): void
    {
        $period = $reopenPeriod->handle($this->user(), $this->period);

        $this->dispatch('app-toast', title: __('Closing undone.'), subtitle: __(':month is open again.', ['month' => Dates::monthName($period->nameDate())]), icon: 'undo');
        $this->redirectRoute('month', ['periodus' => $period->id], navigate: true);
    }

    #[Computed]
    public function period(): Period
    {
        return $this->user()->periods()->findOrFail($this->periodId);
    }

    #[Computed]
    public function offered(): bool
    {
        return app(ReopenPeriod::class)->isOffered($this->user(), $this->period);
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

<div x-data="{
        busy: false,
        async undoClosing() {
            if (this.busy) return
            this.busy = true
            try {
                const dialog = await $wire.dialog()
                if (dialog && await window.appConfirm(dialog)) await $wire.reopen()
            } finally {
                this.busy = false
            }
        },
     }">
    @if ($this->offered)
        <x-ui.button variant="secondary" size="md" icon="undo" class="mt-3 w-full" x-on:click="undoClosing()" x-bind:disabled="busy" data-test="reopen-closing">{{ __('Undo the closing') }}</x-ui.button>
        @error('reopen')
            <div class="mt-2 px-1 text-xs text-pretty text-danger" role="alert" data-test="reopen-error">{{ $message }}</div>
        @enderror
    @endif
</div>
