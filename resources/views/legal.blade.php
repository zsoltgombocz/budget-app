<!DOCTYPE html>
<html lang="hu" class="dark" @env('staging') data-build="dev" @endenv>
    <head>
        @include('partials.head', ['title' => $title])
    </head>
    <body class="min-h-dvh bg-bg font-sans text-ink antialiased">
        <div id="status-shield" aria-hidden="true"></div>
        <main class="mx-auto w-full max-w-2xl px-4 pb-[calc(3rem+env(safe-area-inset-bottom))] pt-[calc(1.5rem+var(--safe-top))]">
            <nav class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm" aria-label="{{ __('Legal documents') }}">
                <a href="{{ url('/') }}" class="mr-auto font-semibold">{{ config('app.name') }}</a>
                @foreach (['aszf' => __('Terms of service'), 'adatvedelem' => __('Privacy policy'), 'impresszum' => __('Imprint'), 'sutik' => __('Cookies')] as $slug => $label)
                    <a href="{{ route('legal', $slug) }}" @class(['text-accent' => $page === $slug, 'text-muted' => $page !== $slug]) @if ($page === $slug) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </nav>
            <article class="legal mt-6" data-test="legal-{{ $page }}">
                {!! $html !!}
            </article>
        </main>
    </body>
</html>
