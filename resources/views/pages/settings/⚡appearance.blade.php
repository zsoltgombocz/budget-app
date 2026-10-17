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
    }

    /**
     * The time zones people here pick most, in words, Budapest first.
     *
     * @return array<string, string> identifier => label
     */
    public function commonTimezones(): array
    {
        return [
            'Europe/Budapest' => __('Budapest (Central European Time)'),
            'Europe/Bucharest' => __('Bucharest, Helsinki (Eastern European Time)'),
            'Europe/London' => __('London, Dublin, Lisbon'),
            'America/New_York' => __('New York (US Eastern Time)'),
            'America/Los_Angeles' => __('Los Angeles (US Pacific Time)'),
            'Asia/Dubai' => __('Dubai'),
            'Australia/Sydney' => __('Sydney'),
        ];
    }

    /**
     * Every other time zone, readable: "Europe / Vienna".
     *
     * @return array<string, string>
     */
    public function otherTimezones(): array
    {
        $zones = [];

        foreach (DateTimeZone::listIdentifiers() as $zone) {
            if (! array_key_exists($zone, $this->commonTimezones())) {
                $zones[$zone] = str_replace(['/', '_'], [' / ', ' '], $zone);
            }
        }

        return $zones;
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
                    <button type="button" x-on:click="$flux.appearance = @js($mode)" :aria-pressed="$flux.appearance === @js($mode) ? 'true' : 'false'"
                            class="focus-ring flex h-24 flex-col items-center justify-center gap-2 rounded-btn border text-sm font-medium transition active:scale-[0.97]"
                            :class="$flux.appearance === @js($mode) ? 'border-accent bg-accent/14 text-accent' : 'border-transparent bg-surface-2 text-ink-2'">
                        <x-ui.icon :name="$option['icon']" :size="26" />
                        {{ $option['label'] }}
                    </button>
                @endforeach
            </div>
        </x-ui.card>

        {{-- Kept in this browser like the theme (resources/js/motion.js); without a choice the system's reduced motion decides. --}}
        <div x-data="{ motion: window.appMotion?.enabled() ?? true }" x-init="$watch('motion', (value) => window.appMotion?.set(value))" class="overflow-hidden rounded-card bg-surface">
            <x-ui.switch-row model="motion" :label="__('Animations')" :hint="__('Short transitions when something opens or changes. Until you choose, it follows your device.')" data-test="motion-toggle" />
        </div>

        <x-ui.card class="p-[18px]">
            <div class="text-[15px] font-semibold">{{ __('Language') }}</div>
            <div class="mt-3 grid grid-cols-2 gap-1.5">
                <x-ui.choice :selected="$locale === 'hu'" wire:click="$set('locale', 'hu')" class="h-11 rounded-xl text-sm" data-test="locale-hu">Magyar</x-ui.choice>
                <x-ui.choice :selected="$locale === 'en'" wire:click="$set('locale', 'en')" class="h-11 rounded-xl text-sm" data-test="locale-en">English</x-ui.choice>
            </div>
        </x-ui.card>

        {{-- A form row like everywhere else in the app: the label left, the friendly zone name right. --}}
        <div>
            <div class="overflow-hidden rounded-card bg-surface">
                <x-ui.select-row wire:model.live="timezone" :label="__('Timezone')" data-test="timezone">
                    <optgroup label="{{ __('Common') }}">
                        @foreach ($this->commonTimezones() as $zone => $zoneLabel)
                            <option value="{{ $zone }}">{{ $zoneLabel }}</option>
                        @endforeach
                    </optgroup>
                    <optgroup label="{{ __('All time zones') }}">
                        @foreach ($this->otherTimezones() as $zone => $zoneLabel)
                            <option value="{{ $zone }}">{{ $zoneLabel }}</option>
                        @endforeach
                    </optgroup>
                </x-ui.select-row>
            </div>
            <p class="px-1.5 pt-2 text-xs leading-snug text-muted">{{ __('Reminders and the day boundary follow this time zone.') }}</p>
        </div>
    </div>
</x-pages::settings.layout>
