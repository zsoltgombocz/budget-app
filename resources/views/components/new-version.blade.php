@php
    use App\Support\Changelog;

    $entry = Changelog::all()[0];
    $fresh = auth()->user()?->created_at?->greaterThan(now()->subDay()) ?? true;
@endphp

{{-- "New version" sheet: once per version and device; brand-new accounts skip it. --}}
<div x-data="{
        open: false,
        version: @js($entry['version']),
        init() {
            let seen = null
            try { seen = localStorage.getItem('seen-version') } catch (e) {}
            if (seen === this.version) return
            if (seen === null && @js($fresh)) { this.remember(); return }
            setTimeout(() => this.open = true, 600)
        },
        remember() { try { localStorage.setItem('seen-version', this.version) } catch (e) {} },
        close() { this.remember(); this.open = false },
     }">
    <x-ui.sheet show="open" close="close()" :label="__('New version')" :header="false" :full="false" z="z-[60]" class="px-1" data-test="new-version">
        <div class="mt-5 flex items-center gap-3">
            <x-ui.icon-tile icon="celebration" tone="accent" :size="48" />
            <div>
                <div class="text-xl font-semibold tracking-[-0.02em]">{{ __('New version is here') }}</div>
                <div class="font-mono text-xs text-accent">{{ $entry['version'] }}</div>
            </div>
        </div>
        <ul class="mt-4 flex flex-col gap-2">
            @foreach (array_slice(Changelog::changes($entry), 0, 4) as $change)
                <li class="flex gap-2.5 text-sm leading-snug text-ink-2"><x-ui.icon name="check" :size="16" :weight="600" class="mt-0.5 text-accent" />{{ $change }}</li>
            @endforeach
        </ul>
        <div class="mt-5 flex gap-2">
            <x-ui.button variant="secondary" class="w-[110px]" x-on:click="close()" data-test="new-version-close">{{ __('Close') }}</x-ui.button>
            <x-ui.button class="flex-1" :href="route('changelog')" x-on:click="remember()" wire:navigate data-test="new-version-open">{{ __('See what’s new') }}</x-ui.button>
        </div>
    </x-ui.sheet>
</div>
