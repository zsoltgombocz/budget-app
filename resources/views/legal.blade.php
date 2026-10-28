<!DOCTYPE html>
<html lang="hu" class="dark" @env('staging') data-build="dev" @endenv>
    <head>
        @include('partials.head', ['title' => $title])
    </head>
    <body class="min-h-dvh bg-bg font-sans text-ink antialiased">
        <div id="status-shield" aria-hidden="true"></div>
        <main class="mx-auto w-full max-w-lg px-4 pb-[calc(3rem+env(safe-area-inset-bottom))] pt-[var(--safe-top)]">
            <div class="flex items-center gap-3 pt-6">
                <x-ui.icon-button icon="arrow_back" :href="$back" :label="__('Back')" data-test="back" />
                <h1 class="min-w-0 truncate text-[26px] font-semibold tracking-[-0.03em]">{{ $title }}</h1>
            </div>

            <nav class="no-scrollbar -mx-4 mt-5 flex gap-2 overflow-x-auto px-4" aria-label="{{ __('Legal documents') }}">
                @foreach (['aszf' => __('Terms of service'), 'adatvedelem' => __('Privacy policy'), 'impresszum' => __('Imprint'), 'sutik' => __('Cookies')] as $slug => $label)
                    <a href="{{ route('legal', $slug) }}" @class(['press flex h-9 shrink-0 items-center rounded-full px-3.5 text-[13px] font-medium', 'bg-ink text-bg' => $page === $slug, 'bg-surface text-ink-2' => $page !== $slug]) @if ($page === $slug) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>

            <article class="legal mt-6 px-1" data-test="legal-{{ $page }}">
                {!! $html !!}
            </article>
        </main>
    </body>
</html>
