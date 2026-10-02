<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Appearance settings')] class extends Component {
    public string $locale = 'hu';

    public string $timezone = 'Europe/Budapest';

    public function mount(): void
    {
        $settings = $this->user()->settings();

        $this->locale = $settings->locale;
        $this->timezone = $settings->timezone;
    }

    /**
     * Every change is saved right away; a new language reloads the page in it.
     */
    public function updated(string $property): void
    {
        $this->validate([
            'locale' => ['required', Rule::in(['hu', 'en'])],
            'timezone' => ['required', 'timezone:all'],
        ]);

        $this->user()->settings()->update(['locale' => $this->locale, 'timezone' => $this->timezone]);

        if ($property === 'locale') {
            app()->setLocale($this->locale);
            $this->redirectRoute('appearance.edit', navigate: true);

            return;
        }

        $this->dispatch('app-toast', title: __('Settings saved.'));
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}; ?>

<x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Theme, language and time zone')">
    <div class="flex flex-col gap-3">
        <x-ui.card class="p-[18px]">
            <div class="text-[15px] font-semibold">{{ __('Theme') }}</div>
            <div x-data class="mt-3 grid grid-cols-3 gap-2" data-test="appearance">
                @foreach (['dark' => ['icon' => 'bedtime', 'label' => __('Dark')], 'light' => ['icon' => 'light_mode', 'label' => __('Light')], 'system' => ['icon' => 'devices', 'label' => __('System')]] as $mode => $option)
                    <button type="button" x-on:click="$flux.appearance = @js($mode)"
                            class="flex h-24 flex-col items-center justify-center gap-2 rounded-btn border text-sm font-medium transition active:scale-[0.97]"
                            :class="$flux.appearance === @js($mode) ? 'border-accent bg-accent/14 text-accent' : 'border-transparent bg-surface-2 text-ink-2'">
                        <x-ui.icon :name="$option['icon']" :size="26" />
                        {{ $option['label'] }}
                    </button>
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.card class="p-[18px]">
            <div class="text-[15px] font-semibold">{{ __('Language') }}</div>
            <div class="mt-3 grid grid-cols-2 gap-1.5">
                <x-ui.choice :selected="$locale === 'hu'" wire:click="$set('locale', 'hu')" class="h-11 rounded-xl text-sm" data-test="locale-hu">Magyar</x-ui.choice>
                <x-ui.choice :selected="$locale === 'en'" wire:click="$set('locale', 'en')" class="h-11 rounded-xl text-sm" data-test="locale-en">English</x-ui.choice>
            </div>
            <div class="mt-4">
                <x-ui.select wire:model.live="timezone" :label="__('Timezone')" data-test="timezone">
                    @foreach (DateTimeZone::listIdentifiers() as $zone)
                        <option value="{{ $zone }}">{{ $zone }}</option>
                    @endforeach
                </x-ui.select>
                <p class="mt-1.5 text-xs text-muted">{{ __('Reminders and the day boundary follow this time zone.') }}</p>
            </div>
        </x-ui.card>
    </div>
</x-pages::settings.layout>
