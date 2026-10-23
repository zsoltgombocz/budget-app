<?php

use App\Models\User;
use App\Services\PeriodService;
use App\Services\Streak;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Morzsa's place: the streak for now; the room, acorns and milestones come with the playful part (F5).
 */
new #[Title('Morzsa')] class extends Component {
    #[Computed]
    public function streak(): int
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return resolve(Streak::class)->forUser($user, resolve(PeriodService::class)->today($user->settings()));
    }
}; ?>

<div class="flex flex-col items-center px-5 pt-10 text-center">
    <h1 class="font-display text-[32px] leading-9 font-bold tracking-[-0.02em]">{{ __('Morzsa') }}</h1>
    <x-morzsa :mood="$this->streak > 0 ? 'buszke' : 'udvozlo'" :size="200" class="mt-6" />
    <p class="mt-6 text-[17px] leading-relaxed text-ink-2" data-test="companion-streak">
        {{ $this->streak > 0
            ? trans_choice('{1} :count day in a row.|[2,*] :count days in a row.', $this->streak, ['count' => $this->streak])
            : __('Write down what you spend today and our streak starts.') }}
    </p>
</div>
