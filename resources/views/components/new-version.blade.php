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
     }" x-cloak>
    <div x-show="open" class="fixed inset-0 z-[60]" role="dialog" aria-modal="true" aria-label="{{ __('New version') }}" data-test="new-version">
        <div x-show="open" x-transition.opacity class="absolute inset-0 bg-black/55" x-on:click="close()"></div>
        <div x-show="open"
             x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-y-full" x-transition:enter-end="translate-y-0"
             x-transition:leave="transition duration-200 ease-in" x-transition:leave-start="translate-y-0" x-transition:leave-end="translate-y-full"
             class="absolute inset-x-0 bottom-0 mx-auto max-w-lg rounded-t-[30px] bg-surface px-5 pb-[calc(1.5rem+env(safe-area-inset-bottom))] pt-2">
            <div class="mx-auto h-[5px] w-9 rounded-full bg-ink/18"></div>
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
        </div>
    </div>
</div>
