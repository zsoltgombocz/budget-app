{{-- Install steps for the detected phone and browser. Expects an Alpine scope with `p` (budgetInstall.platform()). --}}
@php
    $step = fn (string $number, string $html) => '<li class="flex gap-3"><span class="num flex size-6 shrink-0 items-center justify-center rounded-full bg-accent/14 text-xs font-semibold text-accent">'.$number.'</span><span class="pt-0.5">'.$html.'</span></li>';
    $share = '<span class="ms -mb-1 text-ink" style="font-size:18px;width:18px;height:18px">ios_share</span>';
    $dots = '<span class="ms -mb-1 text-ink" style="font-size:18px;width:18px;height:18px">more_vert</span>';
@endphp

<div {{ $attributes->class(['text-sm leading-snug text-ink-2']) }}>
    {{-- iPhone · Safari --}}
    <ol class="flex flex-col gap-2.5" x-show="p.route === 'ios-safari'">
        {!! $step('1', __('Tap the :icon Share button at the bottom of Safari.', ['icon' => $share])) !!}
        {!! $step('2', __('Scroll down and choose “Add to Home Screen”.')) !!}
        {!! $step('3', __('Tap “Add”, then open the app from the Home Screen.')) !!}
    </ol>

    {{-- iPhone · Chrome / Firefox / Edge / in-app --}}
    <div x-show="p.route === 'ios-other'" class="flex flex-col gap-3">
        <template x-if="p.browser === 'chrome'">
            <ol class="flex flex-col gap-2.5">
                {!! $step('1', __('Tap the :icon Share button in the address bar (top right).', ['icon' => $share])) !!}
                {!! $step('2', __('Scroll down in the list and choose “Add to Home Screen”.')) !!}
                {!! $step('3', __('If it is missing: scroll to the end, tap “Edit Actions…” and add “Add to Home Screen”.')) !!}
            </ol>
        </template>
        <template x-if="p.browser !== 'chrome'">
            <p>{{ __('This browser cannot add apps to the Home Screen. Open the page in Safari and install it from there.') }}</p>
        </template>
        <p class="text-xs text-muted" x-show="p.version && p.version < 16.4">{{ __('Your iOS is older than 16.4: installing only works from Safari.') }}</p>
        <a :href="window.budgetInstall.safariUrl()" class="inline-flex h-11 items-center justify-center gap-2 rounded-[14px] bg-surface-2 px-4 text-sm font-medium text-ink" data-test="open-in-safari">
            <span class="ms" style="font-size:18px;width:18px;height:18px">open_in_new</span>{{ __('Open in Safari') }}
        </a>
        <p class="text-xs text-muted">{{ __('The button works on iOS 17 or newer. Otherwise copy the address into Safari.') }}</p>
    </div>

    {{-- Android · Chrome without a prompt yet --}}
    <ol class="flex flex-col gap-2.5" x-show="p.route === 'android-chrome'">
        {!! $step('1', __('Tap the :icon menu at the top right of Chrome.', ['icon' => $dots])) !!}
        {!! $step('2', __('Choose “Install app” (or “Add to Home screen”).')) !!}
        {!! $step('3', __('Confirm with “Install”.')) !!}
    </ol>

    {{-- Android · other browsers and in-app browsers --}}
    <div x-show="p.route === 'android-other'" class="flex flex-col gap-3">
        <p>{{ __('Installing works best from Chrome. Open the page there:') }}</p>
        <a :href="window.budgetInstall.chromeUrl()" class="inline-flex h-11 items-center justify-center gap-2 rounded-[14px] bg-surface-2 px-4 text-sm font-medium text-ink" data-test="open-in-chrome">
            <span class="ms" style="font-size:18px;width:18px;height:18px">open_in_new</span>{{ __('Open in Chrome') }}
        </a>
        <p class="text-xs text-muted" x-show="p.browser === 'samsung' || p.browser === 'firefox'">{{ __('Or in this browser: menu → “Add page to” / “Install” → Home screen.') }}</p>
    </div>
</div>
