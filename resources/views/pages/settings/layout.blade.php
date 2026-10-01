@php
    $tabs = [
        ['route' => 'budget.edit', 'label' => __('Budget')],
        ['route' => 'profile.edit', 'label' => __('Profile')],
        ['route' => 'security.edit', 'label' => __('Security')],
        ['route' => 'appearance.edit', 'label' => __('Appearance')],
    ];
@endphp

<div class="pb-6">
    <nav class="no-scrollbar flex gap-1.5 overflow-x-auto px-4 pt-4" aria-label="{{ __('Settings') }}">
        @foreach ($tabs as $tab)
            @php $active = request()->routeIs($tab['route']); @endphp
            <a href="{{ route($tab['route']) }}" wire:navigate @class([
                'h-9 shrink-0 rounded-xl border px-3.5 text-[13px] font-medium leading-[34px]',
                'border-accent bg-accent/14 text-accent' => $active,
                'border-transparent bg-surface text-ink-2' => ! $active,
            ]) @if ($active) aria-current="page" @endif>{{ $tab['label'] }}</a>
        @endforeach
    </nav>

    <div class="px-4 pt-5">
        @if (filled($heading ?? null))
            <div class="px-1.5">
                <div class="text-lg font-semibold">{{ $heading }}</div>
                @if (filled($subheading ?? null))<div class="mt-0.5 text-sm text-muted">{{ $subheading }}</div>@endif
            </div>
        @endif

        <div class="mt-4 w-full">
            {{ $slot }}
        </div>

        <a href="{{ route('changelog') }}" wire:navigate class="mt-8 flex items-center justify-center gap-2 text-[13px] text-muted" data-test="app-version">
            <span>{{ __('Version :version', ['version' => config('app.version')]) }}</span>
            <span class="text-faint">·</span>
            <span class="font-medium text-accent">{{ __('What’s new') }}</span>
        </a>
    </div>
</div>
